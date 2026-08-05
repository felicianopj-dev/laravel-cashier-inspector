<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use Illuminate\Support\Carbon;

final class WebhookCapture
{
    public ?Carbon $handledAt = null;

    public ?int $durationMs = null;

    public ?int $deliveryId = null;

    public ?int $eventId = null;

    public EventStatus $status = EventStatus::Received;

    public function __construct(
        public readonly string $stripeEventId,
        public readonly string $stripeEventType,
        public readonly ?string $stripeApiVersion,
        public readonly bool $livemode,
        public readonly array $payload,
        public readonly Carbon $receivedAt,
    ) {
    }

    /**
     * Returns null for a payload this package can't identify. Cashier's
     * controller reads $payload['type'] before dispatching WebhookReceived
     * but never touches $payload['id'], so an id-less payload does reach
     * the listener — and capturing it must not be what breaks the webhook
     * request, the same way every other capture failure doesn't.
     */
    public static function fromPayload(array $payload): ?self
    {
        if (! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            return null;
        }

        return new self(
            stripeEventId: $payload['id'],
            stripeEventType: $payload['type'],
            stripeApiVersion: $payload['api_version'] ?? null,
            livemode: (bool) ($payload['livemode'] ?? false),
            payload: $payload,
            receivedAt: Carbon::now(),
        );
    }

    public function markHandled(Carbon $handledAt): void
    {
        $this->handledAt = $handledAt;
        $this->durationMs = (int) $this->receivedAt->diffInMilliseconds($handledAt);
        $this->status = EventStatus::Handled;
    }

    public function markFailed(Carbon $occurredAt): void
    {
        $this->durationMs = (int) $this->receivedAt->diffInMilliseconds($occurredAt);
        $this->status = EventStatus::Failed;
    }

    public function markUnmatched(Carbon $occurredAt): void
    {
        $this->durationMs = (int) $this->receivedAt->diffInMilliseconds($occurredAt);
        $this->status = EventStatus::Unmatched;
    }
}
