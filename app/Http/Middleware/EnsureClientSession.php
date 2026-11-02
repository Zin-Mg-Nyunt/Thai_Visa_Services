<?php

namespace App\Http\Middleware;

use App\Actions\Client\ClientPortalSession;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientSession
{
    /**
     * Allow the request only when the client named in the URL has signed in on this browser
     * with their PIN. Otherwise the visitor goes to that client's PIN page, so a tab left
     * open after signing out returns to its own client's link, never another client's.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Client $client */
        $client = $request->route('client');

        if (! (new ClientPortalSession($request->session()))->isSignedIn($client)) {
            return to_route('track.show', $client->unique_token);
        }

        return $next($request);
    }
}
