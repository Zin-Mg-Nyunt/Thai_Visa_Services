<?php

namespace App\Actions\Landing;

use App\Enums\ServiceAvailability;
use App\Models\Service;

/**
 * The services shown on the public landing page, straight from the services catalogue.
 *
 * A service is listed when it is a main service (sub-services never appear), is active, is marked "show on website" and has not
 * been deleted (the soft-delete scope removes deleted ones). They come in the order they were created. Only what a visitor may
 * see is selected: the two titles and the two public descriptions. The fee, the documents flag, the status and the system key
 * stay on the server.
 *
 * Nothing is cached: it is one indexed query, so an admin's change shows on the next visit and nothing can go stale.
 */
class LandingServices
{
    /**
     * @return list<array{id: int, title: string|null, title_my: string|null, public_description: string|null, public_description_my: string|null}>
     */
    public function forPage(): array
    {
        $services = Service::query()
            ->main()
            ->where('status', ServiceAvailability::Active)
            ->where('show_on_website', true)
            ->orderBy('id')
            ->get(['id', 'title', 'title_my', 'public_description', 'public_description_my'])
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'title' => $service->title,
                'title_my' => $service->title_my,
                'public_description' => $service->public_description,
                'public_description_my' => $service->public_description_my,
            ])
            ->all();

        return array_values($services);
    }
}
