<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Compares the event's livemode flag against which kind of Stripe secret
 * key (sk_test_/sk_live_) the application is currently configured with.
 * A mismatch usually means a leftover webhook endpoint pointed at the
 * wrong environment, or a key swapped without updating the endpoint.
 */
class TestLiveModeMismatchRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $this->configuredMode() !== null;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $configuredMode = $this->configuredMode();
        $eventMode = $event->livemode ? 'live' : 'test';

        if ($configuredMode === $eventMode) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'mode_mismatch',
            title: 'Test/live mode mismatch',
            message: "This event was sent in {$eventMode} mode, but the application is configured with a {$configuredMode} mode Stripe secret key.",
            suggestedChecks: [
                'Confirm which Stripe webhook endpoint (test or live) this application should actually be receiving.',
                'Check STRIPE_SECRET matches the environment this webhook endpoint belongs to.',
            ],
            context: [
                'event_mode' => $eventMode,
                'configured_mode' => $configuredMode,
            ],
        );
    }

    protected function configuredMode(): ?string
    {
        $secret = config('cashier.secret');

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return match (true) {
            str_starts_with($secret, 'sk_live_') => 'live',
            str_starts_with($secret, 'sk_test_') => 'test',
            default => null,
        };
    }
}
