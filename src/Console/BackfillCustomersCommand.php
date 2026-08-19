<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\BillableResolver;
use FelicianoPJ\CashierInspector\Support\CustomerCorrelator;
use Illuminate\Console\Command;

/**
 * Fills in customer and billable associations that were not available at
 * the moment an event was captured.
 *
 * Two things leave an association missing, and neither can be fixed at
 * capture time. A Stripe object that names only its invoice depends on a
 * sibling event that may not have arrived yet, and a billable model whose
 * stripe_id was set only after the webhooks came in never matched
 * anything when they did.
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
    public function handle(CustomerCorrelator $correlator, BillableResolver $billable): int
    {
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

        $this->components->info("Filled the customer on {$customers} event(s), and the billable model on {$billables}.");

        return self::SUCCESS;
    }
}
