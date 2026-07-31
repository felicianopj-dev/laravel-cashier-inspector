<?php

namespace FelicianoPJ\CashierInspector;

use Closure;
use Illuminate\Http\Request;

/**
 * Entry point for configuring dashboard authorization. Apps opt in from
 * their own AppServiceProvider::boot(), similar to Telescope's auth()
 * callback:
 *
 *     CashierInspector::auth(function (Request $request): bool {
 *         return $request->user()?->can('viewCashierInspector') ?? false;
 *     });
 *
 * Without an explicit callback, access is restricted to the local
 * environment, matching the "enabled" config default and keeping the
 * dashboard closed in production until a developer opts in.
 */
class CashierInspector
{
    protected static ?Closure $authUsing = null;

    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        return call_user_func(static::$authUsing ?? static::defaultAuthorization(), $request);
    }

    /**
     * Reset the registered authorization callback. Intended for tests.
     */
    public static function flushState(): void
    {
        static::$authUsing = null;
    }

    protected static function defaultAuthorization(): Closure
    {
        return fn (Request $request) => app()->environment('local');
    }
}
