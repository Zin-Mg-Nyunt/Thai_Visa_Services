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

];
