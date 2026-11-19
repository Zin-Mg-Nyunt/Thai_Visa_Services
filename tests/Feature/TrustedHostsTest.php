<?php

use App\Support\TrustedHosts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * 5f.2 finding 4: only the APP_URL host (plus TRUSTED_HOSTS) is answered. Laravel skips the check in local development and in
 * tests, so the check is driven through a subclass that switches it on.
 */
afterEach(function () {
    Request::setTrustedHosts([]);
    TrustHosts::flushState();
});

/** The framework middleware with the "not in tests" guard removed, using the app's own host list. */
function enforcingTrustHosts(): TrustHosts
{
    TrustHosts::at(TrustedHosts::patterns(...), subdomains: false);

    return new class(app()) extends TrustHosts
    {
        protected function shouldSpecifyTrustedHosts()
        {
            return true;
        }
    };
}

/** Runs a request with the given Host through the middleware and returns the host the app then sees, or null when refused. */
function hostSeenByApp(string $host): ?string
{
    $request = Request::create("http://{$host}/");
    $seen = null;

    try {
        enforcingTrustHosts()->handle($request, function (Request $request) use (&$seen) {
            $seen = $request->getHost();

            return response('ok');
        });
    } catch (SuspiciousOperationException) {
        return null;
    }

    return $seen;
}

test('the host list is the APP_URL host only, matched exactly', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => '']);

    expect(TrustedHosts::patterns())->toBe(['^visa\.example\.com$']);
});

test('TRUSTED_HOSTS entries are added, tidied and not duplicated', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => ' WWW.example.com , ,visa.example.com,']);

    expect(TrustedHosts::patterns())->toBe(['^visa\.example\.com$', '^www\.example\.com$']);
});

test('a request for the APP_URL host is answered, in any letter case', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => '']);

    expect(hostSeenByApp('visa.example.com'))->toBe('visa.example.com')
        ->and(hostSeenByApp('VISA.Example.com'))->toBe('visa.example.com');
});

test('a forged Host is refused: another domain, a subdomain, a look-alike and a suffix trick', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => '']);

    foreach (['evil.test', 'x.visa.example.com', 'visa.example.com.evil.test', 'visaXexample.com', 'evilvisa.example.com'] as $host) {
        expect(hostSeenByApp($host))->toBeNull($host);
    }
});

test('a host listed in TRUSTED_HOSTS is answered too', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => 'www.example.com']);

    expect(hostSeenByApp('www.example.com'))->toBe('www.example.com')
        ->and(hostSeenByApp('evil.test'))->toBeNull();
});

test('the application registers the trusted-host middleware for every request', function () {
    expect(app(Kernel::class)->getGlobalMiddleware())->toContain(TrustHosts::class);
});

test('local development and the test suite are not restricted, so a test request with any host still works', function () {
    $this->withServerVariables(['HTTP_HOST' => 'anything.test'])->get('/up')->assertOk();
});

test('with no usable host the list matches nothing instead of being empty, so production fails closed', function (string $appUrl) {
    config(['app.url' => $appUrl, 'security.trusted_hosts' => '']);

    expect(TrustedHosts::hosts())->toBe([])
        ->and(TrustedHosts::patterns())->toBe([TrustedHosts::NOTHING]);
})->with([
    'empty' => '',
    'no scheme, so no host' => 'visa.example.com',
    'scheme only' => 'https://',
    'no host' => 'http:///path',
    'junk' => '::not a url::',
]);

test('with a missing or malformed APP_URL no host is answered, not even a plausible one', function (string $appUrl) {
    config(['app.url' => $appUrl, 'security.trusted_hosts' => '']);

    foreach (['visa.example.com', 'evil.test', 'localhost'] as $host) {
        expect(hostSeenByApp($host))->toBeNull($host);
    }
})->with(['', 'visa.example.com', 'https://', 'http:///path']);

test('TRUSTED_HOSTS can still rescue a malformed APP_URL, but only with valid hostnames', function () {
    config(['app.url' => '', 'security.trusted_hosts' => 'www.example.com']);

    expect(hostSeenByApp('www.example.com'))->toBe('www.example.com')
        ->and(hostSeenByApp('evil.test'))->toBeNull();
});

test('a TRUSTED_HOSTS entry that is not a plain hostname is ignored, never turned into a wider rule', function (string $entry) {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => $entry]);

    expect(TrustedHosts::hosts())->toBe(['visa.example.com'])
        ->and(hostSeenByApp('evil.test'))->toBeNull()
        ->and(hostSeenByApp('anything.example.com'))->toBeNull();
})->with([
    'wildcard' => '*',
    'wildcard subdomain' => '*.example.com',
    'regular expression' => '.*',
    'regular expression group' => '(.+\\.)?example\\.com',
    'with a scheme' => 'https://www.example.com',
    'with a path' => 'www.example.com/admin',
    'with a space inside' => 'www example.com',
    'with a port' => 'www.example.com:8080',
]);

test('a health-check hostname listed in TRUSTED_HOSTS is answered; an IPv4 address works too', function () {
    config(['app.url' => 'https://visa.example.com', 'security.trusted_hosts' => 'health.internal,203.0.113.10']);

    expect(hostSeenByApp('health.internal'))->toBe('health.internal')
        ->and(hostSeenByApp('203.0.113.10'))->toBe('203.0.113.10')
        ->and(hostSeenByApp('203.0.113.11'))->toBeNull();
});
