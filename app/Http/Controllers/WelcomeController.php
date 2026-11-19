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
     * The public landing page.
     *
     * - Statistics: real totals from the database (cached for ten minutes); only those the admin approved and whose number is not
     *   zero are sent, or null when there are none, which hides the section.
     * - Services: from the services catalogue; an empty list hides the section.
     * - Site: the logo, name and hero image from the website settings, and, once the website is published, the admin's texts and
     *   contact details.
     */
    public function __invoke(LandingStatistics $statistics, LandingServices $services, SiteSettings $site): Response
    {
        return Inertia::render('Welcome', [
            'statistics' => $this->approved($statistics->forPage(), $site->approvedStatistics()),
            'services' => $services->forPage(),
            // The agency name, logo, hero image and, once the website is published, the admin's texts and contact details (public
            // values only); empty values fall back to the built-in ones.
            'site' => $site->forPage(),
        ]);
    }

    /**
     * Only the statistics the admin approved and whose number is not zero; null when there are none, which hides the section.
     *
     * @param  array{clients_served: int, services_provided: int}|null  $counts
     * @param  array<int, string>  $approved
     * @return array<string, int>|null
     */
    private function approved(?array $counts, array $approved): ?array
    {
        $shown = array_filter(
            $counts ?? [],
            fn (int $number, string $name): bool => in_array($name, $approved, true) && $number > 0,
            ARRAY_FILTER_USE_BOTH,
        );

        return $shown === [] ? null : $shown;
    }
}
