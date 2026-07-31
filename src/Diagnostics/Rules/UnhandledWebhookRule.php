<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Cashier's controller returns 200 for any event type it has no handler
 * method for. Often expected (Stripe sends far more event types than
 * Cashier implements), so this is informational rather than a warning.
 */
class UnhandledWebhookRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $this->latestDelivery($event)?->status === EventStatus::Unmatched;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        return DiagnosticResult::info(
            code: 'webhook_unmatched',
            title: 'No Cashier handler for this event type',
            message: "Cashier received \"{$event->stripe_event_type}\" but has no handler method for it, so nothing happened locally.",
            suggestedChecks: [
                'If your application needs to react to this event type, add a handler by extending Cashier\'s WebhookController.',
            ],
        );
    }

    protected function latestDelivery(InspectorEvent $event)
    {
        return $event->deliveries->sortByDesc('received_at')->first();
    }
}
