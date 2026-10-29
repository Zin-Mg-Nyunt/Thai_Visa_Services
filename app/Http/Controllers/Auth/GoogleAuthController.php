<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    /**
     * Send the visitor to Google to sign in.
     */
    public function redirect(): RedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    /**
     * Sign in an existing superadmin whose Google account has been verified.
     * Nobody is created here: the email must already exist in the users table.
     */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        if ($request->has('error')) {
            return $this->denied('auth.google_cancelled');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|ClientException) {
            return $this->denied('auth.google_failed');
        }

        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user === null && ($googleUser->getRaw()['verified_email'] ?? false) === true) {
            $user = User::where('email', $googleUser->getEmail())->whereNull('google_id')->first();
        }

        if ($user === null) {
            return $this->denied('auth.google_not_allowed');
        }

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function denied(string $messageKey): RedirectResponse
    {
        return to_route('login')->withErrors(['email' => __($messageKey)]);
    }
}
