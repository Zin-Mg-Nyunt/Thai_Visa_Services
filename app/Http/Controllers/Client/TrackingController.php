<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\TrackingPinGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyTrackingPinRequest;
use App\Models\Client;
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
     */
    public function show(Request $request, Client $client): Response
    {
        $guard = new TrackingPinGuard($client);

        return Inertia::render('client/Track', [
            'token' => $client->unique_token,
            'lockedMinutes' => $this->minutes($guard->lockedForSeconds()),
        ]);
    }

    /**
     * Check the 4-digit PIN. Wrong PINs are counted for the client as a whole, written to its audit log, and the visitor is told how many tries are left.
     * Once the tries run out the link is locked for 15 minutes, and even the right PIN is refused.
     */
    public function verify(VerifyTrackingPinRequest $request, Client $client): RedirectResponse
    {
        $guard = new TrackingPinGuard($client);

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

        $request->session()->regenerate();
        $request->session()->put('client_id', $client->id);

        return redirect('/client/profile');
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
