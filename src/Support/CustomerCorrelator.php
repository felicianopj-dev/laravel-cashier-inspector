<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recovers the customer for an event that arrived without one, by matching
 * it against events already recorded for the same invoice or subscription.
 *
 * Some Stripe objects reference no customer at all - an invoice_payment
 * names only its invoice - so the customer has to come from a sibling
 * event. The inference is safe rather than a guess: an invoice and a
 * subscription each belong to exactly one customer, so any event sharing
 * one shares the customer too.
 *
 * Correlation runs off the events table's own indexed columns, never the
 * stored payload, so it works the same where payload storage is turned
 * off - which is the default outside local environments.
 */
class CustomerCorrelator
{
    /**
     * Returns the customer id a sibling event recorded, or null when
     * there is nothing to correlate through and when no sibling has been
     * seen yet.
     */
    public function correlate(?string $invoiceId, ?string $subscriptionId): ?string
    {
        // Without at least one correlation id there is no constraint to
        // match on, and an unconstrained query would return whichever
        // customer happens to sort first.
        if (! $invoiceId && ! $subscriptionId) {
            return null;
        }

        return InspectorEvent::query()
            ->whereNotNull('customer_id')
            ->where(function (Builder $query) use ($invoiceId, $subscriptionId) {
                $query->when($invoiceId, fn (Builder $q) => $q->orWhere('invoice_id', $invoiceId))
                    ->when($subscriptionId, fn (Builder $q) => $q->orWhere('subscription_id', $subscriptionId));
            })
            ->value('customer_id');
    }

    /**
     * Fills in the customer id on an attribute set that has none, leaving
     * one that already has a customer untouched.
     */
    public function fill(array $attributes): array
    {
        if (! empty($attributes['customer_id'])) {
            return $attributes;
        }

        $attributes['customer_id'] = $this->correlate(
            $attributes['invoice_id'] ?? null,
            $attributes['subscription_id'] ?? null,
        );

        return $attributes;
    }
}
