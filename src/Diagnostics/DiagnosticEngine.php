<?php

namespace FelicianoPJ\CashierInspector\Diagnostics;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiagnosticEngine
{
    /**
     * @param  DiagnosticRule[]  $rules
     */
    public function __construct(protected array $rules)
    {
    }

    /**
     * Run every registered rule against the event and replace its stored
     * diagnostics with whatever triggered this time.
     */
    public function run(InspectorEvent $event): void
    {
        InspectorDiagnostic::where('event_id', $event->id)->delete();

        foreach ($this->rules as $rule) {
            $this->runRule($rule, $event);
        }
    }

    /**
     * Convenience entry point for listeners that only have an event id
     * (or none, if the event was never persisted) on hand. Never throws:
     * a bug in this package's own diagnostics must not break Cashier's
     * webhook handling.
     */
    public function runForEventId(?int $eventId): void
    {
        if (! $eventId) {
            return;
        }

        try {
            $event = InspectorEvent::find($eventId);

            if ($event) {
                $this->run($event);
            }
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector failed to run diagnostics.', [
                'exception' => $e,
            ]);
        }
    }

    protected function runRule(DiagnosticRule $rule, InspectorEvent $event): void
    {
        // Persisting is inside the same try as the rule itself: a failure
        // writing one diagnostic must not skip the remaining rules.
        try {
            if (! $rule->supports($event)) {
                return;
            }

            $result = $rule->diagnose($event);

            if (! $result->isTriggered()) {
                return;
            }

            InspectorDiagnostic::create([
                'event_id' => $event->id,
                'rule' => $rule::class,
                'code' => $result->code,
                'severity' => Severity::from($result->status->value),
                'title' => $result->title,
                'message' => $result->message,
                'context' => $result->context + ($result->suggestedChecks !== [] ? ['suggested_checks' => $result->suggestedChecks] : []),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Cashier Inspector diagnostic rule failed.', [
                'rule' => $rule::class,
                'exception' => $e,
            ]);
        }
    }
}
