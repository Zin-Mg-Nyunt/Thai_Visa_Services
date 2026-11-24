<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAuthController extends Controller
{
    /**
     * Send the visitor to Google to sign in.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless(self::isConfigured(), 404);

        $driver = Socialite::driver('google');

        // The real driver asks Google to show its account chooser. (Socialite's test fake wraps the real driver and offers no such call.)
        if ($driver instanceof AbstractProvider) {
            $driver = $driver->with(['prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    /**
     * Sign in an existing user whose Google account has a verified email that is the email address saved in the app.
     *
     * - The email saved in the app is the authority, and it is compared on EVERY sign-in. A Google account that is already linked
     *   (its `sub`, kept in `users.google_id`) never skips the comparison, so after the email is changed the old Google account
     *   can no longer sign in.
     * - A Google account whose email is verified by Google and equals the user's current email may take over the link: the new
     *   `sub` replaces the old one and the email is marked verified. This is how a changed email gets verified (the app sends
     *   no email).
     * - A `sub` that is already linked to another user is never moved to this one.
     * - Nobody is created here: the email must already exist in the users table.
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

        $subject = trim((string) $googleUser->getId());
        $email = self::normalizeEmail($googleUser->getEmail());

        // The email claim must be explicitly true: missing, false or any other value is not a verification.
        $claims = $googleUser instanceof AbstractUser ? $googleUser->getRaw() : [];

        if ($subject === '' || $email === '' || ($claims['verified_email'] ?? false) !== true) {
            return $this->denied('auth.google_unverified');
        }

        try {
            $user = DB::transaction(fn (): ?User => $this->userForGoogleAccount($subject, $email));
        } catch (UniqueConstraintViolationException) {
            // Another request linked the same Google account to a user at the same moment.
            return $this->denied('auth.google_not_allowed');
        }

        if ($user === null) {
            return $this->denied('auth.google_not_allowed');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Find the user this Google account may sign in as, and update the link and the verified state. Returns null, having
     * changed nothing, when the account may not sign in. Runs inside a transaction, with the user row locked.
     */
    private function userForGoogleAccount(string $subject, string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

        if ($user === null) {
            return null;
        }

        if ($user->google_id === $subject) {
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        }

        // Never move a Google account from one user to another.
        if (User::query()->where('google_id', $subject)->whereKeyNot($user->getKey())->lockForUpdate()->exists()) {
            return null;
        }

        $user->forceFill(['google_id' => $subject, 'email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * The one way emails are compared: trimmed and lower-cased (multibyte safe).
     */
    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
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
