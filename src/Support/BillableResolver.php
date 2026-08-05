<?php

namespace FelicianoPJ\CashierInspector\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
     * ponytail: an email search matching more billable models than this
     * silently ignores the rest, rather than building an unbounded IN (...)
     * clause. Narrow the term instead; paginate the lookup if that ever
     * stops being good enough.
     */
    protected const EMAIL_MATCH_LIMIT = 500;

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

    /**
     * Finds local billable models whose email matches a search term.
     *
     * Searching by email is resolved live against the application's own
     * customer table rather than against anything this package stores, so
     * redaction never gets in the way: the address is matched where it
     * already lives, and no copy of it is persisted here.
     *
     * Only Cashier's configured customer model is searched. The events
     * table records billable_type polymorphically, but Cashier resolves
     * customers through a single Cashier::$customerModel, so an event
     * whose billable_type is something else cannot be matched by email.
     * The column is assumed to be `email`, matching the assumption
     * Cashier's own stripeEmail() makes; a model that overrides
     * stripeEmail() to read a different column won't be found.
     *
     * Never throws, for the same reason resolve() doesn't.
     *
     * @return array{billable_type: string, billable_ids: array<int, int|string>}|null
     */
    public function idsMatchingEmail(string $term): ?array
    {
        try {
            $model = Cashier::$customerModel;

            if (! class_exists($model)) {
                return null;
            }

            $instance = new $model;

            $hasEmail = Schema::connection($instance->getConnectionName())
                ->hasColumn($instance->getTable(), 'email');

            if (! $hasEmail) {
                return null;
            }

            $ids = $model::query()
                ->where('email', 'like', "%{$term}%")
                ->limit(self::EMAIL_MATCH_LIMIT)
                ->pluck($instance->getKeyName())
                ->all();

            if ($ids === []) {
                return null;
            }

            return ['billable_type' => $model, 'billable_ids' => $ids];
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to search billable models by email.', [
                'exception' => $e,
            ]);

            return null;
        }
    }
}
