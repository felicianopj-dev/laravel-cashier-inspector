<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\BillableResolver;
use FelicianoPJ\CashierInspector\Support\CustomerCorrelator;
use FelicianoPJ\CashierInspector\Support\StripeEventAttributes;
use Illuminate\Console\Command;

/**
 * Fills in customer and billable associations that were not available at
 * the moment an event was captured.
 *
 * Three things leave an association missing, and none can be fixed at
 * capture time. A Stripe object that names only its invoice depends on a
 * sibling event that may not have arrived yet; a billable model whose
 * stripe_id was set only after the webhooks came in never matched
 * anything when they did; and a row captured by an earlier version has
 * blank correlation columns because that version was not reading them,
 * which no amount of correlating between columns can recover.
 *
 * Only ever fills blanks: an event that already carries a customer or a
 * billable model is left exactly as it was.
 */
class BackfillCustomersCommand extends Command
{
    protected $signature = 'cashier-inspector:backfill-customers';

    protected $description = 'Fill in customer and billable associations missing from recorded events';

    /**
     * ponytail: one pass. A row whose sibling is filled later in the same
     * pass stays blank until the command is run again, which is cheap
     * enough to just do. Loop until nothing changes if that ever stops
     * being good enough.
     */
    public function handle(
        CustomerCorrelator $correlator,
        BillableResolver $billable,
        StripeEventAttributes $attributes,
    ): int {
        $recovered = $this->recoverFromPayloads($attributes);
        $customers = 0;
        $billables = 0;

        InspectorEvent::query()
            ->whereNull('customer_id')
            ->where(fn ($query) => $query->whereNotNull('invoice_id')->orWhereNotNull('subscription_id'))
            ->chunkById(100, function ($events) use ($correlator, &$customers) {
                foreach ($events as $event) {
                    $customerId = $correlator->correlate($event->invoice_id, $event->subscription_id);

                    if (! $customerId) {
                        continue;
                    }

                    $event->update(['customer_id' => $customerId]);
                    $customers++;
                }
            });

        InspectorEvent::query()
            ->whereNotNull('customer_id')
            ->whereNull('billable_id')
            ->chunkById(100, function ($events) use ($billable, &$billables) {
                foreach ($events as $event) {
                    $resolved = $billable->resolve($event->customer_id);

                    if (! $resolved['billable_id']) {
                        continue;
                    }

                    $event->update($resolved);
                    $billables++;
                }
            });

        $this->components->info("Recovered ids from the stored payload on {$recovered} event(s), filled the customer on {$customers}, and the billable model on {$billables}.");

        return self::SUCCESS;
    }

    /**
     * Re-reads the stored payload of every event missing a correlation id
     * and fills whatever the current extraction finds. This runs first, so
     * the pass below has the recovered invoice and subscription ids to
     * correlate customers through.
     *
     * Only blanks are filled, so a column the payload no longer supports -
     * a redaction that grew to cover it, say - never overwrites a value
     * already recorded. Rows stored with payload storage off carry nothing
     * to recover and are skipped.
     */
    protected function recoverFromPayloads(StripeEventAttributes $attributes): int
    {
        // ponytail: the filter matches nearly every stored row - a charge
        // event has no subscription, invoice or checkout session and never
        // will - so each run decodes every retained payload, though only
        // the first run after an upgrade can find anything. Bounded by
        // storage.retention_days, which is 7 by default. Give the table a
        // "recovered at" column if a long retention window ever makes the
        // rescan hurt.
        $recovered = 0;

        InspectorEvent::query()
            ->whereNotNull('payload')
            ->where(fn ($query) => $query->whereNull('customer_id')
                ->orWhereNull('subscription_id')
                ->orWhereNull('invoice_id')
                ->orWhereNull('checkout_session_id'))
            ->chunkById(100, function ($events) use ($attributes, &$recovered) {
                foreach ($events as $event) {
                    // The array cast yields whatever the column decodes
                    // to, so a corrupt or hand-edited row can hand back a
                    // scalar. Skipping it keeps one bad row from aborting
                    // the run with earlier chunks already committed.
                    if (! is_array($event->payload)) {
                        continue;
                    }

                    $fill = array_filter(
                        $attributes->correlationIds($event->payload),
                        fn ($value, $column) => $value !== null && $event->{$column} === null,
                        ARRAY_FILTER_USE_BOTH,
                    );

                    if (! $fill) {
                        continue;
                    }

                    $event->update($fill);
                    $recovered++;
                }
            });

        return $recovered;
    }
}
