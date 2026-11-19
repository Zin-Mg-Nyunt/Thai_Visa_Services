<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureRateLimiting();
    }

    /**
     * Document downloads: 10 a minute for each client and IP address. A client opening a few documents never gets near it,
     * and a person or script hammering the route is stopped at 10 access-history rows a minute. A blocked request gets
     * 429 before the app writes anything, so the history cannot be flooded.
     *
     * Worker phone lookups (Add worker dialog): 30 a minute for each signed-in admin. A lookup answers with a person's name,
     * birthday and gender, so a stolen session or a script must not be able to walk through phone numbers. An admin adding
     * workers looks up one number every several seconds at the most, far below the limit. A guest is sent to the login page
     * before the limit is reached. Every signed-in user is counted on a budget of their own, so another user's requests (which
     * are refused anyway) never use up an admin's lookups. A blocked lookup gets 429 and no data.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('worker-lookups', function (Request $request): Limit {
            return Limit::perMinute(30)->by('worker-lookups|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('document-downloads', function (Request $request): Limit {
            $client = $request->route('client');
            $clientKey = $client instanceof Client ? $client->getKey() : (string) $client;

            return Limit::perMinute(10)->by('document-downloads|'.$clientKey.'|'.$request->ip());
        });
    }

    /**
     * Permissions that have no model of their own. The routes also check the superadmin role.
     */
    protected function configureGates(): void
    {
        Gate::define('viewDashboard', fn (User $user): bool => $user->isSuperadmin());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Outside production, reading a column the query did not select throws instead of quietly giving null
        // (a missing money column once turned into 0 in the service totals).
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
