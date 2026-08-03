<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Compares the event's Stripe customer id against the billable resolution
 * already performed at capture time (BillableResolver, via
 * Cashier::findBillable()). A miss usually means the customer was created
 * directly in Stripe rather than through this application's own
 * subscribe/checkout flow.
 */
class MissingBillableModelRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return filled($event->customer_id);
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        if (filled($event->billable_id)) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'missing_billable_model',
            title: 'No local billable model for this Stripe customer',
            message: "Stripe customer \"{$event->customer_id}\" is referenced by this event, but no local billable model has a matching stripe_id.",
            suggestedChecks: [
                'Confirm a billable model in this application was created for this Stripe customer.',
                'If Cashier::useCustomerModel() is customized, check it points to the right model.',
            ],
            context: [
                'customer_id' => $event->customer_id,
            ],
        );
    }
}
