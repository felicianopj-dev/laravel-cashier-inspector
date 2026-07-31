<?php

namespace FelicianoPJ\CashierInspector\Http\Middleware;

use Closure;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Terminating middleware, pushed onto the global HTTP kernel stack so it
 * observes Cashier's webhook route regardless of which middleware group
 * the application registered it under. Records the response status as a
 * fallback signal, in case an abnormal ending wasn't seen by the
 * exception reporting hook.
 */
class RecordWebhookOutcome
{
    public function __construct(
        protected WebhookCaptureContext $context,
        protected DiagnosticEngine $diagnostics,
    ) {
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

        $status = $response->getStatusCode();
        $this->context->recordTerminatedStatus($status);

        $capture = $this->context->current();

        if (! $capture || $capture->status !== EventStatus::Received || ! $capture->deliveryId) {
            return;
        }

        try {
            if ($status >= 200 && $status < 300) {
                // Received but no WebhookHandled: Cashier had no handler
                // method for this event type and returned early.
                $capture->markUnmatched(now());

                InspectorDelivery::whereKey($capture->deliveryId)->update([
                    'status' => EventStatus::Unmatched,
                    'severity' => Severity::Info,
                    'duration_ms' => $capture->durationMs,
                ]);
            } else {
                // Fallback: the request ended abnormally but the exception
                // reporting hook didn't record it (e.g. a fatal error).
                $capture->markFailed(now());

                InspectorDelivery::whereKey($capture->deliveryId)->update([
                    'status' => EventStatus::Failed,
                    'severity' => Severity::Error,
                    'duration_ms' => $capture->durationMs,
                    'exception_message' => "Webhook request ended with HTTP status {$status} without a matching exception report.",
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to record a webhook outcome.', [
                'exception' => $e,
            ]);

            return;
        }

        $this->diagnostics->runForEventId($capture->eventId);
    }
}
