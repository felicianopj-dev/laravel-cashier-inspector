<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Subscription;

/**
 * Compares the event's subscription id against Cashier's own subscriptions
 * table. A miss usually means the create/update handler for an earlier
 * event never ran, or ran against the wrong billable model.
 */
class MissingLocalSubscriptionRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return filled($event->subscription_id);
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        if (Subscription::where('stripe_id', $event->subscription_id)->exists()) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'missing_local_subscription',
            title: 'No local Cashier subscription for this event',
            message: "Stripe subscription \"{$event->subscription_id}\" is referenced by this event, but no local Cashier subscription record matches it.",
            suggestedChecks: [
                'Confirm this subscription belongs to a billable model in this application.',
                'Check whether an earlier subscription-create event was received and processed successfully.',
            ],
            context: [
                'subscription_id' => $event->subscription_id,
            ],
        );
    }
}
