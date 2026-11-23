<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Profile', [
            'googleEnabled' => GoogleAuthController::isConfigured(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $before = GoogleAuthController::normalizeEmail($user->email);

        $user->fill($request->validated());

        // A new email address is not verified until the user signs in again with a Google account that has it. Nothing is
        // sent: the app has no outgoing email. A change of capital letters only is the same address (emails are compared
        // case-insensitively), so it keeps the verification.
        $emailChanged = GoogleAuthController::normalizeEmail($user->email) !== $before;

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', $emailChanged
            ? ['type' => 'warning', 'message' => __('Profile updated. Your new email address is not verified yet: sign out, then sign in with the Google account that has this email address.')]
            : ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
