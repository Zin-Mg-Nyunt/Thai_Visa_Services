<?php

namespace App\Http\Middleware;

use App\Actions\Site\SiteSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default. Only the user fields the pages use are shared,
     * never the whole model (Google ID, two-factor fields and so on stay on the server).
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // The name beside the logo on every page (the agency name from Website content), or null to show the logo alone.
            'logoName' => fn (): ?string => app(SiteSettings::class)->logoName(),
            'locale' => app()->getLocale(),
            'auth' => [
                // Built only when a page is rendered. The raw role is read, as the role middleware does,
                // so an account with an unknown role is refused with 403 instead of failing here.
                'user' => fn () => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->getRawOriginal('role'),
                    'email_verified_at' => $request->user()->email_verified_at?->toIso8601String(),
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
