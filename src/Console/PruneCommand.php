<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes events older than the configured retention period, cascading
 * to their deliveries and diagnostics. Not auto-scheduled: reliably
 * registering a schedule across the supported Laravel range (^11.0 to
 * ^13.0) isn't predictable enough to do automatically, so applications
 * add this to their own scheduler instead.
 */
class PruneCommand extends Command
{
    protected $signature = 'cashier-inspector:prune {--days= : Override the configured retention period, in days}';

    protected $description = 'Delete Cashier Inspector events older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cashier-inspector.storage.retention_days'));

        if ($days <= 0) {
            $this->components->warn('Retention period must be a positive number of days.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $count = InspectorEvent::where('created_at', '<', $cutoff)->delete();

        $this->components->info("Pruned {$count} event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
