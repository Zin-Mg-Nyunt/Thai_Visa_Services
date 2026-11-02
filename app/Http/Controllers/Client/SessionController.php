<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\ClientPortalSession;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * Sign out the client named in the URL. Other clients and a superadmin signed in
     * on the same browser stay signed in. The client goes back to their own PIN page.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        (new ClientPortalSession($request->session()))->signOut($client);

        return to_route('track.show', $client->unique_token);
    }
}
