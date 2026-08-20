<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;

/**
 * Builds the cashier_inspector_events row attributes from a raw Stripe
 * webhook payload: core identifying fields, best-effort correlation ids
 * pulled from the event's data.object, and the payload itself (redacted,
 * gated by the store_payloads config).
 */
class StripeEventAttributes
{
    /**
     * The correlation columns, each mapped to the Stripe object type whose
     * own id fills it and the field other objects reference it by.
     *
     * Both directions matter. An event whose object *is* the customer
     * carries no nested customer field, and an event about something else
     * carries no id of its own worth recording - so reading only one of
     * the two leaves a column empty on half the event types that could
     * have filled it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const CORRELATION_IDS = [
        'customer_id' => ['customer', 'customer'],
        'subscription_id' => ['subscription', 'subscription'],
        'invoice_id' => ['invoice', 'invoice'],
        'checkout_session_id' => ['checkout.session', 'checkout_session'],
    ];

    public function __construct(protected PayloadRedactor $redactor)
    {
    }

    public function extract(array $payload): array
    {
        $attributes = [
            'stripe_event_type' => $payload['type'],
            'stripe_api_version' => $payload['api_version'] ?? null,
            'livemode' => (bool) ($payload['livemode'] ?? false),
            'payload' => config('cashier-inspector.storage.store_payloads')
                ? $this->redactor->redact($payload)
                : null,
        ];

        return $attributes + $this->correlationIds($payload);
    }

    /**
     * The correlation columns alone, for callers that already hold a
     * stored payload and want nothing rebuilt from it - the backfill
     * command re-reading rows captured before a column was being read.
     *
     * @return array<string, string|null>
     */
    public function correlationIds(array $payload): array
    {
        $object = $payload['data']['object'] ?? [];
        $objectType = $object['object'] ?? null;

        $ids = [];

        foreach (self::CORRELATION_IDS as $column => [$type, $reference]) {
            $ids[$column] = $this->stringOrNull(
                $objectType === $type ? ($object['id'] ?? null) : ($object[$reference] ?? null)
            );
        }

        return $ids;
    }

    /**
     * The mask is rejected as well as a non-string, because correlation
     * ids are also read back out of payloads that were already redacted on
     * the way in - the backfill command's recovery pass. A configured
     * redaction path covering a correlation field would otherwise write
     * the mask itself into an indexed, searchable column, where it reads
     * as a real id and groups unrelated events under it. No genuine Stripe
     * id can collide with the mask.
     */
    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === $this->redactor->mask()) {
            return null;
        }

        return $value;
    }
}
