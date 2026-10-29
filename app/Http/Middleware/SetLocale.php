<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the language the browser has selected: the X-Locale header on
     * in-page requests, or the locale cookie on full-page navigations such as
     * the return from Google sign-in.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach ([$request->header('X-Locale'), $request->cookie('locale')] as $locale) {
            if (is_string($locale) && in_array($locale, config('app.supported_locales'), true)) {
                App::setLocale($locale);

                break;
            }
        }

        return $next($request);
    }
}
