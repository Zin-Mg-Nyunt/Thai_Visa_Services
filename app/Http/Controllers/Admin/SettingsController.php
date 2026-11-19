<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings hub of the admin area: the admin's own account, the two website screens, and two read-only facts (whether the
 * admin's Google sign-in is linked, and the currency). Nothing is edited here; every row either links to a screen that has its own
 * protection or only shows a state.
 */
class SettingsController extends Controller
{
    /** Every amount is in Thai baht in version 1; it cannot be changed. */
    public const CURRENCY = 'THB';

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManageWebsite = Gate::allows('manage-site-settings');

        return Inertia::render('admin/settings/Index', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'profile_url' => route('profile.edit'),
                'security_url' => route('security.edit'),
            ],
            // Only an admin who may manage the website settings is offered these links.
            'appearance_url' => $canManageWebsite ? route('admin.settings.appearance.edit') : null,
            'content_url' => $canManageWebsite ? route('admin.settings.content.edit') : null,
            // Only whether the Google account is linked, never the Google id itself.
            'google_linked' => $user->getRawOriginal('google_id') !== null,
            'currency' => self::CURRENCY,
        ]);
    }
}
