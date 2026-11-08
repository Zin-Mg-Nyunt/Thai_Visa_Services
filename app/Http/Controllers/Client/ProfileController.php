<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientPortalServiceResource;
use App\Http\Resources\ClientProfileResource;
use App\Models\Client;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the signed-in client their own details and their own services only: the services are read through this
     * client, so another client's rows can never be listed. The payments are loaded with them, so the money is
     * worked out without a query per service.
     */
    public function show(Client $client): Response
    {
        $clientServices = $client->clientServices()
            ->with([
                'service:id,title,title_my',
                'subService:id,title,title_my',
                'payments:id,client_service_id,amount',
            ])
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('client/Profile', [
            'token' => $client->unique_token,
            'profile' => ClientProfileResource::make($client),
            'services' => ClientPortalServiceResource::collection($clientServices),
        ]);
    }
}
