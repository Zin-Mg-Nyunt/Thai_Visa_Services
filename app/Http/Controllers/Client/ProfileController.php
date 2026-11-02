<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the signed-in client their own page. The full portal comes in a later phase.
     */
    public function show(Client $client): Response
    {
        return Inertia::render('client/Profile', [
            'token' => $client->unique_token,
            'clientName' => $client->name,
        ]);
    }
}
