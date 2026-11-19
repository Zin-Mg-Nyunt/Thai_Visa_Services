<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings page of the admin area. For now it only links to the Website appearance screen (Section 4.11); the full hub
 * (admin account, website content, Google sign-in state, currency) is a later step.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/settings/Index', [
            // Only an admin who may manage the website settings is offered the link.
            'appearance_url' => Gate::allows('manage-site-settings') ? route('admin.settings.appearance.edit') : null,
        ]);
    }
}
