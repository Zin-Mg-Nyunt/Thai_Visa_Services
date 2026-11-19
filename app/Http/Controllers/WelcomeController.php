<?php

namespace App\Http\Controllers;

use App\Actions\Landing\LandingServices;
use App\Actions\Landing\LandingStatistics;
use App\Actions\Site\SiteSettings;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * The public landing page. Its statistics are real totals from the database (cached for ten minutes), or null when there is
     * nothing to show yet, which hides the section. Its services come from the services catalogue and are an empty list when
     * none is shown, which hides that section too. The logo, name and hero image come from the website settings.
     */
    public function __invoke(LandingStatistics $statistics, LandingServices $services, SiteSettings $site): Response
    {
        return Inertia::render('Welcome', [
            'statistics' => $statistics->forPage(),
            'services' => $services->forPage(),
            // The agency name, logo and hero image the admin set (public values only); empty values fall back to the built-in ones.
            'site' => $site->forPage(),
        ]);
    }
}
