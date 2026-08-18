<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

class RecordWebhookHandled
{
    public function __construct(
        protected WebhookCaptureContext $context,
        protected DiagnosticEngine $diagnostics,
        protected StepRecorder $steps,
    ) {
    }

    public function handle(WebhookHandled $event): void
    {
        $capture = $this->context->current();

        if (! $capture) {
            return;
        }

        $this->steps->end(Step::CashierHandler, StepStatus::Ok, "Cashier handled {$capture->stripeEventType}.");

        $capture->markHandled(now());

        if (! $capture->deliveryId) {
            return;
        }

        try {
            InspectorDelivery::whereKey($capture->deliveryId)->update([
                'status' => EventStatus::Handled,
                'severity' => Severity::Success,
                'handled_at' => $capture->handledAt,
                'duration_ms' => $capture->durationMs,
            ]);
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to record a handled webhook.', [
                'exception' => $e,
            ]);

            return;
        }

        $this->steps->begin(Step::Diagnostics);
        $this->diagnostics->runForEventId($capture->eventId);
        $this->steps->end(Step::Diagnostics, StepStatus::Ok, $this->steps->describeDiagnostics($capture->eventId));
    }
}
