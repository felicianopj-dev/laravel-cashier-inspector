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
    public function __construct(protected PayloadRedactor $redactor)
    {
    }

    public function extract(array $payload): array
    {
        $object = $payload['data']['object'] ?? [];
        $objectType = $object['object'] ?? null;

        return [
            'stripe_event_type' => $payload['type'],
            'stripe_api_version' => $payload['api_version'] ?? null,
            'livemode' => (bool) ($payload['livemode'] ?? false),
            'payload' => config('cashier-inspector.storage.store_payloads')
                ? $this->redactor->redact($payload)
                : null,
            'customer_id' => $this->stringOrNull($object['customer'] ?? null),
            'subscription_id' => $this->subscriptionId($object, $objectType),
            'invoice_id' => $objectType === 'invoice' ? $this->stringOrNull($object['id'] ?? null) : null,
            'checkout_session_id' => $objectType === 'checkout.session' ? $this->stringOrNull($object['id'] ?? null) : null,
        ];
    }

    protected function subscriptionId(array $object, ?string $objectType): ?string
    {
        if ($objectType === 'subscription') {
            return $this->stringOrNull($object['id'] ?? null);
        }

        return $this->stringOrNull($object['subscription'] ?? null);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
