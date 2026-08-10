<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Contracts\EnvironmentDiagnostic;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Without STRIPE_WEBHOOK_SECRET, Cashier's VerifyWebhookSignature
 * middleware never runs, so nothing confirms incoming requests actually
 * came from Stripe. Applies to every event since the exposure is
 * account-wide, not specific to any one delivery.
 */
class MissingWebhookSecretRule implements DiagnosticRule, EnvironmentDiagnostic
{
    public function supports(InspectorEvent $event): bool
    {
        return true;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        if (filled(config('cashier.webhook.secret'))) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'webhook_secret_missing',
            title: 'Stripe webhook secret is not configured',
            message: 'STRIPE_WEBHOOK_SECRET is not set, so Cashier cannot verify that incoming webhook requests actually came from Stripe.',
            suggestedChecks: [
                'Set STRIPE_WEBHOOK_SECRET in your .env to the signing secret from your Stripe webhook endpoint settings.',
            ],
        );
    }
}
