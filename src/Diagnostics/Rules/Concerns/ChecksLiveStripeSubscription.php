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
     * stripe-php has no per-call timeout, only a process-wide default HTTP
     * client (Stripe\ApiRequestor::setHttpClient()), so applying one here
     * means reaching into global state the host application also uses. The
     * previous client is captured and restored around the call: without
     * that, enabling these checks would silently force every later Stripe
     * call in the process — Cashier's own, and the application's — onto
     * this package's timeout, and would keep doing so for the life of an
     * Octane or queue worker.
     *
     * ApiRequestor::httpClient() lazily installs the shared default client
     * when nothing was set, so the restore is a no-op in that case rather
     * than clearing a client someone else configured.
     *
     * ponytail: still process-wide for the duration of the call itself, so
     * a concurrent Stripe call in the same process (Octane, fibers) can see
     * this timeout. Upgrade path: drop all of this once stripe-php supports
     * a scoped per-request client.
     */
    protected function liveSubscription(Subscription $subscription): StripeSubscription
    {
        $timeout = (int) config('cashier-inspector.stripe_api_checks.timeout_seconds', 5);

        $client = new CurlClient();
        $client->setTimeout($timeout);
        $client->setConnectTimeout($timeout);

        $previous = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($client);

        try {
            return $subscription->asStripeSubscription();
        } finally {
            ApiRequestor::setHttpClient($previous);
        }
    }
}
