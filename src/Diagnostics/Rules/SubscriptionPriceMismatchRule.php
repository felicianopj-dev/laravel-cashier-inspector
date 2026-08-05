<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\Concerns\ChecksLiveStripeSubscription;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Compares the local subscription_items' stripe_price set against a live
 * fetch from Stripe. Opt-in (config('cashier-inspector.stripe_api_checks.enabled'))
 * — disabled by default since it makes a live Stripe API call synchronously
 * during webhook handling. Compares sets (order-independent) to support
 * multi-price subscriptions.
 */
class SubscriptionPriceMismatchRule implements DiagnosticRule
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

        $localPrices = $subscription->items()->pluck('stripe_price')->sort()->values();

        $stripeSubscription = $this->liveSubscription($subscription);
        $stripePrices = collect($stripeSubscription->items->data)
            ->pluck('price.id')
            ->sort()
            ->values();

        if ($localPrices->all() === $stripePrices->all()) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'subscription_price_mismatch',
            title: 'Local and Stripe subscription prices differ',
            message: "Local subscription \"{$subscription->stripe_id}\" has price(s) [{$localPrices->implode(', ')}], but Stripe currently reports [{$stripePrices->implode(', ')}].",
            suggestedChecks: [
                'Confirm the latest subscription-update webhook was received and processed.',
                'Check whether the price changed in Stripe outside of a webhook this application has processed.',
            ],
            context: [
                'subscription_id' => $subscription->stripe_id,
                'local_prices_as_of_now' => $localPrices->all(),
                'stripe_prices_as_of_now' => $stripePrices->all(),
            ],
        );
    }
}
