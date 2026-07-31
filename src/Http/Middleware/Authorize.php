<?php

namespace FelicianoPJ\CashierInspector\Http\Middleware;

use Closure;
use FelicianoPJ\CashierInspector\CashierInspector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every dashboard and internal API route. Disabled entirely
 * (404) unless the "enabled" config is on, and further gated (403) by
 * the CashierInspector authorization callback.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cashier-inspector.enabled')) {
            abort(404);
        }

        if (! CashierInspector::check($request)) {
            abort(403);
        }

        return $next($request);
    }
}
