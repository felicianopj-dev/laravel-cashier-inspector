<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\DiagnosticReport;
use Illuminate\Console\Command;

class EventCommand extends Command
{
    protected $signature = 'cashier-inspector:event {event : The Stripe event id, for example evt_123}';

    protected $description = 'Show what was captured and diagnosed for one Stripe event';

    /**
     * The report printed here is the same text the dashboard's copy button
     * produces, so an event described from an SSH session and one pasted
     * from a browser read identically. The delivery attempts are listed
     * separately because the report only names the latest one, and the
     * count is the whole point when a duplicate delivery is the problem.
     */
    public function handle(): int
    {
        $id = $this->argument('event');

        $event = InspectorEvent::where('stripe_event_id', $id)->first();

        if (! $event) {
            $this->components->error("No captured event with the Stripe event id [{$id}].");

            return self::FAILURE;
        }

        $deliveries = $event->deliveries()->orderByDesc('received_at')->get();
        $diagnostics = $event->diagnostics()->orderByDesc('created_at')->get();

        $this->newLine();
        $this->line(DiagnosticReport::generate($event, $deliveries->first(), $diagnostics));
        $this->newLine();

        $this->line('Delivery attempts:');
        $this->table(
            ['Received', 'Status', 'Severity', 'Duration'],
            $deliveries->map(fn (InspectorDelivery $delivery) => [
                $delivery->received_at?->toDateTimeString() ?? '-',
                $delivery->status->value,
                $delivery->severity->value,
                $delivery->duration_ms === null ? '-' : "{$delivery->duration_ms} ms",
            ])->all()
        );

        return self::SUCCESS;
    }
}
