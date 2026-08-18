<?php

namespace FelicianoPJ\CashierInspector\Http\Middleware;

use Closure;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in route middleware, attached to Cashier's own webhook route.
 *
 * The package's default capture works without it: an exception reporting
 * hook for detail and a terminating middleware as a fallback signal. That
 * pair has one gap - an exception the application does not report produces
 * only the fallback, with no class, message or trace.
 *
 * Wrapping the controller directly closes it: every Throwable is seen
 * whether or not the application reports it.
 *
 * Note where this sits. Cashier applies VerifyWebhookSignature from its
 * controller's constructor, so that is controller middleware and runs
 * inside the route's dispatch - after this. Signature verification is
 * therefore still not separately measurable, and a rejected signature never
 * reaches this package at all, since Cashier dispatches no event for it.
 *
 * Cashier's behaviour is untouched: the exception is recorded and rethrown.
 */
class InstrumentCashierWebhook
{
    public function __construct(
        protected WebhookCaptureContext $context,
        protected StepRecorder $steps,
        protected DiagnosticEngine $diagnostics,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Routing is done and Cashier's controller is next. Closing the
        // phase here means the receive listener's own end() is a no-op, so
        // it is recorded once, bounded by the route rather than by the
        // listener that happens to run first.
        $this->steps->end(Step::RequestReceived, StepStatus::Ok, "Reached Cashier's webhook route.");

        try {
            return $next($request);
        } catch (Throwable $e) {
            $this->record($e);

            throw $e;
        }
    }

    /**
     * Records a Throwable the controller let escape. The reporting hook may
     * never see it - an application is free not to report it - and it is
     * what turns a bare 500 into a diagnosis.
     */
    protected function record(Throwable $e): void
    {
        $capture = $this->context->current();

        if (! $capture || $capture->status !== EventStatus::Received || ! $capture->deliveryId) {
            return;
        }

        $occurredAt = Carbon::now();

        $this->steps->closeOpen(StepStatus::Failed, get_class($e).': '.$e->getMessage(), $occurredAt);

        $capture->markFailed($occurredAt);

        try {
            InspectorDelivery::whereKey($capture->deliveryId)->update([
                'status' => EventStatus::Failed,
                'severity' => Severity::Error,
                'duration_ms' => $capture->durationMs,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_trace' => config('cashier-inspector.storage.store_exception_traces')
                    ? $e->getTraceAsString()
                    : null,
            ]);
        } catch (Throwable $recordingException) {
            // Capture must never be what breaks the webhook request.
            Log::warning('Cashier Inspector failed to record a webhook exception.', [
                'exception' => $recordingException,
            ]);

            return;
        }

        // Resolving the capture here means the reporting hook will bail when
        // it sees this exception, so the diagnostics it would have run have
        // to run from here instead.
        $this->steps->begin(Step::Diagnostics);
        $this->diagnostics->runForEventId($capture->eventId);
        $this->steps->end(Step::Diagnostics, StepStatus::Ok, $this->steps->describeDiagnostics($capture->eventId));
    }
}
