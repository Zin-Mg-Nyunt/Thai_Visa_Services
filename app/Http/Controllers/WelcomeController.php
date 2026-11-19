<?php

namespace App\Http\Controllers;

use App\Actions\Landing\LandingServices;
use App\Actions\Landing\LandingStatistics;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * The public landing page. Its statistics are real totals from the database (cached for ten minutes), or null when there is
     * nothing to show yet, which hides the section. Its services come from the services catalogue and are an empty list when
     * none is shown, which hides that section too.
     */
    public function __invoke(LandingStatistics $statistics, LandingServices $services): Response
    {
        return Inertia::render('Welcome', [
            'statistics' => $statistics->forPage(),
            'services' => $services->forPage(),
        ]);
    }
}
