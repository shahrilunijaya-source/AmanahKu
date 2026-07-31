<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the request locale from the `amanahku-lang` cookie so server-rendered
 * text — chiefly validation errors — matches the in-app EN|BM guidance toggle.
 * Whitelisted to known locales; anything else falls through to the app default.
 */
class SetLocale
{
    private const SUPPORTED = ['en', 'ms'];

    public function handle(Request $request, Closure $next): Response
    {
        $lang = (string) $request->cookie('amanahku-lang', '');

        if (in_array($lang, self::SUPPORTED, true)) {
            App::setLocale($lang);
        }

        return $next($request);
    }
}
