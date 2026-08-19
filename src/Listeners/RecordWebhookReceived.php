<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\BillableResolver;
use FelicianoPJ\CashierInspector\Support\CustomerCorrelator;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
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
        protected BillableResolver $billable,
        protected CustomerCorrelator $correlator,
        protected StepRecorder $steps,
    ) {
    }

    public function handle(WebhookReceived $event): void
    {
        $this->steps->end(Step::RequestReceived, StepStatus::Ok, 'Signature accepted.');
        $this->steps->begin(Step::EventCaptured);

        $capture = WebhookCapture::fromPayload($event->payload);

        // Always started, even with nothing to capture: that clears any
        // capture left over from an earlier request in a long-running
        // worker, so the handled/failed listeners can't attach this
        // request's outcome to the previous request's delivery.
        $this->context->start($capture);

        if (! $capture) {
            Log::warning('Cashier Inspector skipped a webhook payload with no usable event id or type.');

            $this->steps->end(Step::EventCaptured, StepStatus::Failed, 'The payload carried no usable event id or type.');

            return;
        }

        try {
            $attributes = $this->attributes->extract($event->payload);

            // An event that names no customer of its own can still belong
            // to one, through an invoice or subscription already seen.
            $attributes = $this->correlator->fill($attributes);

            $attributes += $this->billable->resolve($attributes['customer_id']);

            $inspectorEvent = InspectorEvent::updateOrCreate(
                ['stripe_event_id' => $capture->stripeEventId],
                $attributes
            );

            $delivery = $inspectorEvent->deliveries()->create([
                'status' => EventStatus::Received,
                'received_at' => $capture->receivedAt,
            ]);

            $capture->deliveryId = $delivery->id;
            $capture->eventId = $inspectorEvent->id;

            $this->steps->end(Step::EventCaptured, StepStatus::Ok, $inspectorEvent->billable_type
                ? "Billable model resolved: {$inspectorEvent->billable_type} #{$inspectorEvent->billable_id}."
                : 'No local billable model matched this customer.');
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to record a received webhook.', [
                'exception' => $e,
            ]);

            $this->steps->end(Step::EventCaptured, StepStatus::Failed, $e->getMessage());
        }

        // Everything between here and WebhookHandled is Cashier's handler,
        // plus any other listeners the application registers on these two
        // events.
        $this->steps->begin(Step::CashierHandler);
    }
}
