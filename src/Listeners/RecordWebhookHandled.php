<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

class RecordWebhookHandled
{
    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    public function handle(WebhookHandled $event): void
    {
        $capture = $this->context->current();

        if (! $capture) {
            return;
        }

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
        }
    }
}
