<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | The policy (see App\Support\ContentSecurityPolicy) is sent on every HTML page. While `enforce` is false it is sent as
    | Content-Security-Policy-Report-Only: the browser lists what it would have blocked in its console but blocks nothing, so
    | the site cannot break. Browse the app, check the console is clean, then set CSP_ENFORCE=true to enforce it.
    |
    */

    'csp' => [
        'enforce' => (bool) env('CSP_ENFORCE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Sent only in production and only over https (never over plain http, which is how the app runs in development). Six months,
    | without includeSubDomains or preload, so it cannot lock other subdomains and stays easy to undo.
    |
    */

    'hsts' => [
        'max_age' => 15552000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted hosts
    |--------------------------------------------------------------------------
    |
    | Outside local development and tests, a request is answered only when its Host header is the host of APP_URL (exactly: not its
    | subdomains) or one of the hosts in TRUSTED_HOSTS. Any other Host gets a 400, so a forged Host cannot put another domain into a
    | link the app builds. The check fails closed: if APP_URL has no usable host and TRUSTED_HOSTS is empty, EVERY request gets a 400
    | (it never falls back to trusting everything).
    |
    | TRUSTED_HOSTS is a comma-separated list of bare hostnames, no scheme, port, path or wildcard. Use it for:
    |   - the www name, if the site is also served on it (APP_URL holds the main domain);
    |   - the name a health check or load balancer calls (the /up route is host-checked too).
    | An entry that is not a plain hostname is ignored (never trusted).
    |
    */

    'trusted_hosts' => (string) env('TRUSTED_HOSTS', ''),

];
