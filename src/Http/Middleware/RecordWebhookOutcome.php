<?php

namespace FelicianoPJ\CashierInspector\Http\Middleware;

use Closure;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        protected StepRecorder $steps,
    ) {
    }

    /**
     * The earliest point this package sees the request. Global middleware
     * runs before routing, so CashierWebhookRoute::matches() cannot answer
     * yet - it resolves the route's controller, which is the whole reason
     * it doesn't care what URI the application mounted Cashier at. The
     * timeline is therefore opened for every request and only ever written
     * for one that turns out to be a webhook, since nothing else reaches
     * the flush.
     *
     * The clock comes from PHP's own request start where it is available,
     * which is earlier and more honest than this middleware's own now().
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = $request->server('REQUEST_TIME_FLOAT');

        $this->steps->reset();
        $this->steps->begin(
            Step::RequestReceived,
            is_numeric($startedAt) ? Carbon::createFromTimestampMs((int) ($startedAt * 1000)) : null
        );

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! CashierWebhookRoute::matches($request)) {
            return;
        }

        $status = $response->getStatusCode();

        $capture = $this->context->current();

        if (! $capture || ! $capture->deliveryId) {
            return;
        }

        // Already resolved by the handled listener or the exception hook.
        // Nothing left to record but the response itself.
        if ($capture->status !== EventStatus::Received) {
            $this->recordResponse($capture->status, $status, $capture->deliveryId);

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

        // Cashier had no handler for this event type, so the handler phase
        // never reported an ending and is recorded as skipped.
        $this->steps->closeOpen(
            $capture->status === EventStatus::Unmatched ? StepStatus::Skipped : StepStatus::Failed,
            $capture->status === EventStatus::Unmatched
                ? 'Cashier has no handler for this event type.'
                : "Request ended with HTTP status {$status}."
        );

        $this->steps->begin(Step::Diagnostics);
        $this->diagnostics->runForEventId($capture->eventId);
        $this->steps->end(Step::Diagnostics, StepStatus::Ok, $this->steps->describeDiagnostics($capture->eventId));

        $this->recordResponse($capture->status, $status, $capture->deliveryId);
    }

    protected function recordResponse(EventStatus $outcome, int $status, int $deliveryId): void
    {
        $this->steps->mark(
            Step::Response,
            $outcome === EventStatus::Failed ? StepStatus::Failed : StepStatus::Ok,
            "HTTP {$status}, recorded as {$outcome->value}."
        );

        $this->steps->flush($deliveryId);
    }
}
