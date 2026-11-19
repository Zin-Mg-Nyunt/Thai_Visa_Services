<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on responses.
 *
 * - Every response: X-Content-Type-Options: nosniff, so a browser never guesses a type for a file or a page.
 * - The tracking and client portal pages (/track/* and /client/*): they are private to one client and their address holds the
 *   client's secret token, so they must not be indexed and the address must not leak to other sites through the Referer
 *   header. They get X-Robots-Tag: noindex and Referrer-Policy: no-referrer. (The page itself also carries a robots noindex
 *   meta tag, see resources/views/app.blade.php.)
 *
 * The public landing page and the admin pages get only nosniff.
 */
class SecurityHeaders
{
    /** The paths that belong to one client and are never indexed. */
    public const PRIVATE_CLIENT_PATHS = ['track/*', 'client/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($request->is(self::PRIVATE_CLIENT_PATHS)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
