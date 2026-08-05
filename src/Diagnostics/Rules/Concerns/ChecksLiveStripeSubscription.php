<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules\Concerns;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Subscription;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Subscription as StripeSubscription;

/**
 * Shared fetch logic for diagnostic rules that compare local Cashier state
 * against a live Stripe subscription. Both liveSubscription() and
 * localSubscription() are overridable on purpose: tests override
 * liveSubscription() in an anonymous subclass instead of hitting the
 * network.
 */
trait ChecksLiveStripeSubscription
{
    protected function localSubscription(InspectorEvent $event): ?Subscription
    {
        return Subscription::where('stripe_id', $event->subscription_id)->first();
    }

    /**
     * ponytail: stripe-php has no per-call timeout, only a process-wide
     * default HTTP client (Stripe\ApiRequestor::setHttpClient()). Acceptable
     * here since this is the only code path in the package making a live
     * Stripe call inline during webhook handling. Upgrade path: drop this
     * once stripe-php supports a scoped client, or if a host app's own
     * Stripe calls need a different timeout than this package's checks.
     */
    protected function liveSubscription(Subscription $subscription): StripeSubscription
    {
        $timeout = (int) config('cashier-inspector.stripe_api_checks.timeout_seconds', 5);

        $client = new CurlClient();
        $client->setTimeout($timeout);
        $client->setConnectTimeout($timeout);
        ApiRequestor::setHttpClient($client);

        return $subscription->asStripeSubscription();
    }
}
