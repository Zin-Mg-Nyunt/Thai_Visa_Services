<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\ClientPortalSession;
use App\Actions\Client\TrackingPinGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyTrackingPinRequest;
use App\Models\Client;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    /**
     * Show the PIN screen for a tracking link. Nothing about the client is sent to the page,
     * only how long the link stays locked, so the form can stay disabled after a reload.
     * A client already signed in with this link goes straight to their profile.
     */
    public function show(Request $request, Client $client): Response|RedirectResponse
    {
        if ((new ClientPortalSession($request->session()))->isSignedIn($client)) {
            return to_route('client.profile', $client->unique_token);
        }

        $guard = new TrackingPinGuard($client);

        return Inertia::render('client/Track', [
            'token' => $client->unique_token,
            'lockedMinutes' => $this->minutes($guard->lockedForSeconds()),
        ]);
    }

    /**
     * Check the 4-digit PIN. Wrong PINs are counted for the client as a whole, written to its audit log, and the visitor is told how many tries are left.
     * Once the tries run out the link is locked for 15 minutes, and even the right PIN is refused.
     * Checks for one client run one at a time, so guesses sent together are all counted.
     */
    public function verify(VerifyTrackingPinRequest $request, Client $client): RedirectResponse
    {
        $guard = new TrackingPinGuard($client);

        try {
            return $guard->oneCheckAtATime(fn () => $this->check($request, $client, $guard));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['pin' => __('auth.pin_busy')]);
        }
    }

    private function check(VerifyTrackingPinRequest $request, Client $client, TrackingPinGuard $guard): RedirectResponse
    {
        $this->isLocked($guard);

        if (! hash_equals($client->pin(), $request->validated('pin'))) {
            $client->pinAttempts()->create([
                'ip_address' => (string) $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'caused_lock' => $guard->recordFailure(),
            ]);

            $this->isLocked($guard);

            throw ValidationException::withMessages([
                'pin' => trans_choice('auth.pin_incorrect', $guard->attemptsRemaining()),
            ]);
        }

        $guard->recordSuccess();

        (new ClientPortalSession($request->session()))->signIn($client);

        return to_route('client.profile', $client->unique_token);
    }

    private function isLocked(TrackingPinGuard $guard): void
    {
        $seconds = $guard->lockedForSeconds();

        if ($seconds > 0) {
            throw ValidationException::withMessages([
                'pin' => __('auth.pin_throttle', ['minutes' => $this->minutes($seconds)]),
            ]);
        }
    }

    private function minutes(int $seconds): int
    {
        return (int) ceil($seconds / 60);
    }
}
