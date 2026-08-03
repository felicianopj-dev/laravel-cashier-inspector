<?php

namespace FelicianoPJ\CashierInspector\Support;

use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Resolves the local billable model for a Stripe customer id, the same way
 * Cashier itself does (Cashier::findBillable(), matching on stripe_id).
 * Kept separate from StripeEventAttributes since this one hits the
 * database, unlike that class's pure payload extraction.
 */
class BillableResolver
{
    /**
     * Never throws: a misconfigured Cashier::$customerModel (or any other
     * lookup failure) must not prevent the event itself from being
     * captured, the same way a diagnostic rule failing must not.
     *
     * @return array{billable_type: ?string, billable_id: int|string|null}
     */
    public function resolve(?string $customerId): array
    {
        if (! $customerId) {
            return ['billable_type' => null, 'billable_id' => null];
        }

        try {
            $billable = Cashier::findBillable($customerId);
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to resolve a billable model.', [
                'exception' => $e,
            ]);

            return ['billable_type' => null, 'billable_id' => null];
        }

        return [
            'billable_type' => $billable ? $billable::class : null,
            'billable_id' => $billable?->getKey(),
        ];
    }
}
