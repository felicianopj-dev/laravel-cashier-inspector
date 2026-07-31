<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\StripeEventAttributes;
use FelicianoPJ\CashierInspector\Support\WebhookCapture;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

class RecordWebhookReceived
{
    public function __construct(
        protected WebhookCaptureContext $context,
        protected StripeEventAttributes $attributes,
    ) {
    }

    public function handle(WebhookReceived $event): void
    {
        $capture = WebhookCapture::fromPayload($event->payload);
        $this->context->start($capture);

        try {
            $inspectorEvent = InspectorEvent::updateOrCreate(
                ['stripe_event_id' => $capture->stripeEventId],
                $this->attributes->extract($event->payload)
            );

            $delivery = $inspectorEvent->deliveries()->create([
                'status' => EventStatus::Received,
                'received_at' => $capture->receivedAt,
            ]);

            $capture->deliveryId = $delivery->id;
            $capture->eventId = $inspectorEvent->id;
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to record a received webhook.', [
                'exception' => $e,
            ]);
        }
    }
}
