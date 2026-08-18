<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registered against the framework's exception reporting hook to capture
 * exceptions thrown while Cashier's webhook controller was handling a
 * request, before WebhookHandled fires.
 */
class ReportPreHandledFailure
{
    public function __construct(
        protected WebhookCaptureContext $context,
        protected DiagnosticEngine $diagnostics,
        protected StepRecorder $steps,
    ) {
    }

    public function __invoke(Throwable $e): void
    {
        if (! CashierWebhookRoute::matches(request())) {
            return;
        }

        $occurredAt = Carbon::now();

        $capture = $this->context->current();

        if (! $capture || $capture->status !== EventStatus::Received || ! $capture->deliveryId) {
            return;
        }

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
            Log::warning('Cashier Inspector failed to record a failed webhook.', [
                'exception' => $recordingException,
            ]);

            return;
        }

        $this->steps->begin(Step::Diagnostics);
        $this->diagnostics->runForEventId($capture->eventId);
        $this->steps->end(Step::Diagnostics, StepStatus::Ok, $this->steps->describeDiagnostics($capture->eventId));
    }
}
