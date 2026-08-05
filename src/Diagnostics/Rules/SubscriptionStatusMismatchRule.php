<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\Concerns\ChecksLiveStripeSubscription;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Compares the local subscription's stripe_status against a live fetch from
 * Stripe. Opt-in (config('cashier-inspector.stripe_api_checks.enabled')) —
 * disabled by default since it makes a live Stripe API call synchronously
 * during webhook handling.
 */
class SubscriptionStatusMismatchRule implements DiagnosticRule
{
    use ChecksLiveStripeSubscription;

    public function supports(InspectorEvent $event): bool
    {
        return config('cashier-inspector.stripe_api_checks.enabled', false)
            && filled($event->subscription_id);
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $subscription = $this->localSubscription($event);

        if (! $subscription) {
            return DiagnosticResult::passed();
        }

        $stripeSubscription = $this->liveSubscription($subscription);

        if ($stripeSubscription->status === $subscription->stripe_status) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'subscription_status_mismatch',
            title: 'Local and Stripe subscription status differ',
            message: "Local status is \"{$subscription->stripe_status}\", but Stripe currently reports \"{$stripeSubscription->status}\" for subscription \"{$subscription->stripe_id}\".",
            suggestedChecks: [
                'Confirm the latest subscription-update webhook was received and processed.',
                'Check whether the subscription changed in Stripe outside of a webhook this application has processed.',
            ],
            context: [
                'subscription_id' => $subscription->stripe_id,
                'local_status_as_of_now' => $subscription->stripe_status,
                'stripe_status_as_of_now' => $stripeSubscription->status,
            ],
        );
    }
}
