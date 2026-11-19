<?php

namespace App\Support;

/**
 * The Content Security Policy of the app: which places a page may load scripts, styles, fonts, images and connections from, so
 * that content injected into a page cannot run or call out.
 *
 * - Scripts: only the app's own files. No inline script and no eval; the page data is a JSON block, which is data and not run.
 * - Styles: the app's own files and inline style attributes (the interface libraries set some).
 * - Fonts: the app's own files (they are bundled, nothing comes from a font service) and data addresses.
 * - Images: the app's own, data and blob addresses (previews and the QR code), and the public Spaces bucket when the website images
 *   are served from one.
 * - Connections: the app itself (the Inertia requests).
 * - No plug-ins, the page cannot change its base address, forms post only to the app, and no other site may frame the page.
 * - While the Vite dev server runs (development only), its address and its websocket are allowed too.
 */
class ContentSecurityPolicy
{
    /**
     * @param  string|null  $viteOrigin  the Vite dev server's origin, in development only
     * @param  string|null  $assetsOrigin  the origin the public website images are served from, when it is not this app
     */
    public static function header(?string $viteOrigin = null, ?string $assetsOrigin = null): string
    {
        $dev = $viteOrigin === null ? [] : [$viteOrigin];
        $devSocket = $viteOrigin === null ? [] : [preg_replace('#^http#', 'ws', $viteOrigin)];
        $assets = $assetsOrigin === null ? [] : [$assetsOrigin];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            'font-src' => ["'self'", 'data:', ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:', ...$assets, ...$dev],
            'connect-src' => ["'self'", ...$dev, ...$devSocket],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return implode('; ', array_map(fn (string $name, array $sources): string => $name.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    /**
     * The origin (scheme, host and port) of an address, or null when it is not a full address (a path on this site).
     */
    public static function origin(?string $url): ?string
    {
        $parts = parse_url((string) $url);

        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
