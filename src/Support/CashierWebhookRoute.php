<?php

namespace FelicianoPJ\CashierInspector\Support;

use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Throwable;

/**
 * Identifies whether the current request was routed to Cashier's webhook
 * controller (or a subclass of it), without depending on the URI the
 * application chose to mount it at.
 */
class CashierWebhookRoute
{
    public static function matches(?Request $request): bool
    {
        $route = $request?->route();

        if (! $route) {
            return false;
        }

        try {
            $controller = $route->getController();
        } catch (Throwable) {
            return false;
        }

        return $controller instanceof WebhookController;
    }
}
