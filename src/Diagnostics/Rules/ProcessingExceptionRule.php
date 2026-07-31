<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

/**
 * Surfaces the most recent delivery attempt's exception through the same
 * diagnostics pathway as every other finding, rather than leaving it only
 * visible in the raw delivery attempts table.
 */
class ProcessingExceptionRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $this->latestDelivery($event)?->status === EventStatus::Failed;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $delivery = $this->latestDelivery($event);

        if (! $delivery?->exception_class) {
            return DiagnosticResult::skipped();
        }

        return DiagnosticResult::error(
            code: 'processing_exception',
            title: 'Webhook processing threw an exception',
            message: "Cashier's handler for \"{$event->stripe_event_type}\" threw {$delivery->exception_class}: {$delivery->exception_message}",
            suggestedChecks: [
                'Check the application log around the received time for the full stack trace.',
                'Reproduce locally by resending this event with the Stripe CLI or dashboard.',
            ],
            context: [
                'exception_class' => $delivery->exception_class,
                'exception_message' => $delivery->exception_message,
            ],
        );
    }

    protected function latestDelivery(InspectorEvent $event)
    {
        return $event->deliveries->sortByDesc('received_at')->first();
    }
}
