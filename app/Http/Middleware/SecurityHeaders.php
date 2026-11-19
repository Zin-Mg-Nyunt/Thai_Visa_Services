<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on responses.
 *
 * - Every response: X-Content-Type-Options: nosniff, so a browser never guesses a type for a file or a page, and
 *   X-Frame-Options: DENY, so no other site can show a page of the app inside a frame (clickjacking).
 * - In production over https: Strict-Transport-Security (six months, config/security.php), so browsers keep using https.
 * - Every HTML page: the Content Security Policy (App\Support\ContentSecurityPolicy). It is sent as Report-Only until
 *   CSP_ENFORCE is turned on: the browser reports what it would block and blocks nothing.
 * - The tracking and client portal pages (/track/* and /client/*): they are private to one client and their address holds the
 *   client's secret token, so they must not be indexed and the address must not leak to other sites through the Referer
 *   header. They get X-Robots-Tag: noindex and Referrer-Policy: no-referrer. (The page itself also carries a robots noindex
 *   meta tag, see resources/views/app.blade.php.)
 *
 * The public landing page and the admin pages get only the headers for every response, the policy on HTML pages, and HSTS in
 * production.
 */
class SecurityHeaders
{
    /** The paths that belong to one client and are never indexed. */
    public const PRIVATE_CLIENT_PATHS = ['track/*', 'client/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('security.hsts.max_age'));
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set(
                config('security.csp.enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
                $this->policy(),
            );
        }

        if ($request->is(self::PRIVATE_CLIENT_PATHS)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    /**
     * The policy for this environment. In development, while the Vite dev server runs, its address is allowed too; in production
     * it never is, even if a leftover hot file exists.
     */
    private function policy(): string
    {
        $vite = ! app()->isProduction() && Vite::isRunningHot() ? ContentSecurityPolicy::origin(trim((string) file_get_contents(Vite::hotFile()))) : null;

        return ContentSecurityPolicy::header($vite, ContentSecurityPolicy::origin((string) config('filesystems.disks.public_assets.url')));
    }
}
