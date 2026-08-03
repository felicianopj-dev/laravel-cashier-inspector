<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

class SlowProcessingRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $this->latestDelivery($event)?->duration_ms !== null;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $delivery = $this->latestDelivery($event);
        $threshold = (int) config('cashier-inspector.diagnostics.slow_processing_threshold_ms', 5000);

        if ($delivery->duration_ms <= $threshold) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'slow_processing',
            title: 'Webhook processing was slow',
            message: "This delivery took {$delivery->duration_ms} ms to process, above the configured threshold of {$threshold} ms.",
            suggestedChecks: [
                'Check for slow queries or external API calls in the webhook handler.',
                'Confirm the handler isn\'t doing synchronous work that should be queued.',
            ],
            context: [
                'duration_ms' => $delivery->duration_ms,
                'threshold_ms' => $threshold,
            ],
        );
    }

    protected function latestDelivery(InspectorEvent $event)
    {
        return $event->deliveries->sortByDesc('received_at')->first();
    }
}
