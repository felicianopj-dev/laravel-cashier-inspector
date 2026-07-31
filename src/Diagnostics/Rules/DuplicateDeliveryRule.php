<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * The whole reason cashier_inspector_deliveries is a separate table from
 * cashier_inspector_events: redeliveries of the same logical Stripe event
 * show up as multiple rows instead of being silently overwritten.
 */
class DuplicateDeliveryRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $event->deliveries->count() > 1;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $count = $event->deliveries->count();

        return DiagnosticResult::warning(
            code: 'duplicate_delivery',
            title: 'Stripe delivered this event more than once',
            message: "This event was delivered {$count} times. Stripe retries webhooks it doesn't get a timely 2xx response for.",
            suggestedChecks: [
                'Confirm your webhook endpoint responds quickly, and with a 2xx status, even before slow processing finishes.',
                'Check the delivery attempts below for timeouts or crashes on earlier attempts.',
            ],
            context: [
                'delivery_count' => $count,
                'statuses' => $event->deliveries->pluck('status.value')->all(),
            ],
        );
    }
}
