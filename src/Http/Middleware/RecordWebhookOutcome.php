<?php

namespace FelicianoPJ\CashierInspector\Http\Middleware;

use Closure;
use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminating middleware, pushed onto the global HTTP kernel stack so it
 * observes Cashier's webhook route regardless of which middleware group
 * the application registered it under. Records the response status as a
 * fallback signal, in case an abnormal ending wasn't seen by the
 * exception reporting hook.
 */
class RecordWebhookOutcome
{
    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! CashierWebhookRoute::matches($request)) {
            return;
        }

        $this->context->recordTerminatedStatus($response->getStatusCode());
    }
}
