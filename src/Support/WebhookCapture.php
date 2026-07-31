<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use Illuminate\Support\Carbon;

final class WebhookCapture
{
    public ?Carbon $handledAt = null;

    public ?int $durationMs = null;

    public ?int $deliveryId = null;

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

    public static function fromPayload(array $payload): self
    {
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

    public function markUnmatched(): void
    {
        $this->status = EventStatus::Unmatched;
    }
}
