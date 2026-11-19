<?php

namespace App\Support;

/**
 * The Host headers the app answers (5f.2 finding 4). Used by `trustHosts()` in bootstrap/app.php; Laravel skips the check in local
 * development and in tests, so the check itself is tested through a subclass of the framework middleware.
 *
 * It fails closed. Laravel trusts every Host when the list it is given is empty, so an empty result here would silently turn the
 * protection off when APP_URL is missing or malformed. Instead, with no usable host, patterns() returns one that matches nothing,
 * and every request gets a 400.
 */
class TrustedHosts
{
    /** A regular expression that matches no Host at all. */
    public const NOTHING = '(?!)';

    /**
     * Regular-expression patterns for Request::setTrustedHosts: the APP_URL host and each TRUSTED_HOSTS entry, each matched exactly
     * (no subdomains). Never empty.
     *
     * @return array<int, string>
     */
    public static function patterns(): array
    {
        $hosts = self::hosts();

        if ($hosts === []) {
            return [self::NOTHING];
        }

        return array_map(fn (string $host): string => '^'.preg_quote($host).'$', $hosts);
    }

    /**
     * The valid, lower-case, distinct hostnames: the host of APP_URL and the TRUSTED_HOSTS entries. Anything that is not a plain
     * hostname (a scheme, a port, a path, a wildcard, a space, a regular expression) is dropped.
     *
     * @return array<int, string>
     */
    public static function hosts(): array
    {
        $candidates = [
            parse_url((string) config('app.url'), PHP_URL_HOST),
            ...explode(',', (string) config('security.trusted_hosts')),
        ];

        $hosts = [];

        foreach ($candidates as $candidate) {
            $host = is_string($candidate) ? strtolower(trim($candidate)) : '';

            if ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
