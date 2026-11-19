<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientPortalServiceResource;
use App\Http\Resources\ClientProfileResource;
use App\Models\Client;
use App\Models\ClientService;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the signed-in client their own details and their own services only: the services are read through this
     * client, so another client's rows can never be listed. The payments are loaded with them, so the money is
     * worked out without a query per service. The documents are loaded only for the services whose documents the client may
     * see (ClientService::showsDocumentsToClient), in one query: Job Search documents and those of a cancelled service are never
     * read for the portal at all.
     */
    public function show(Client $client): Response
    {
        $clientServices = $client->clientServices()
            ->with([
                'service:id,title,title_my,key,has_documents',
                'subService:id,title,title_my',
                'payments:id,client_service_id,amount',
            ])
            ->latest()
            ->latest('id')
            ->get();

        $clientServices->filter(fn (ClientService $clientService) => $clientService->showsDocumentsToClient())
            ->load(['documents' => fn ($query) => $query->select(['id', 'client_service_id', 'title', 'client_can_download'])->oldest('id')]);

        return Inertia::render('client/Profile', [
            'token' => $client->unique_token,
            'profile' => ClientProfileResource::make($client),
            'services' => ClientPortalServiceResource::collection($clientServices),
        ]);
    }
}
