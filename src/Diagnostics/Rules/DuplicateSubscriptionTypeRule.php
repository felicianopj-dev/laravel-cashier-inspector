<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Subscription;

/**
 * Flags a billable model that ends up with more than one valid (active,
 * trialing, or on grace period) local Cashier subscription sharing the same
 * type — Cashier's subscription($type) helper only ever resolves one.
 * Canceled/ended rows are excluded on purpose: resubscribing to the same
 * type after a prior cancellation is normal and must not trigger this.
 */
class DuplicateSubscriptionTypeRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return filled($event->subscription_id);
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $subscription = Subscription::where('stripe_id', $event->subscription_id)->first();

        if (! $subscription) {
            return DiagnosticResult::passed();
        }

        $siblings = Subscription::where('user_id', $subscription->user_id)
            ->where('type', $subscription->type)
            ->get()
            ->filter->valid();

        if ($siblings->count() <= 1) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'duplicate_subscription_type',
            title: 'Multiple active subscriptions share the same Cashier type',
            message: "This billable model has {$siblings->count()} active/trialing subscriptions of type \"{$subscription->type}\", but Cashier's subscription(\"{$subscription->type}\") only resolves one.",
            suggestedChecks: [
                'Confirm only one subscription per type is expected for this billable model.',
                'If multiple subscriptions are intentional, use distinct Cashier types for each.',
            ],
            context: [
                'type' => $subscription->type,
                'subscription_ids' => $siblings->pluck('stripe_id')->all(),
            ],
        );
    }
}
