<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local-only fake clock. When the session carries a `dev_now` timestamp, every
 * now()/today() call in the request reads it instead of the wall clock.
 */
class DevClock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal() && ($now = $request->session()->get('dev_now'))) {
            Carbon::setTestNow(Carbon::parse($now));
        }

        return $next($request);
    }
}
