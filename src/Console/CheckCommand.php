<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Health\HealthCheck;
use FelicianoPJ\CashierInspector\Health\HealthReport;
use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'cashier-inspector:check';

    protected $description = 'Report whether Cashier Inspector, Cashier, and Stripe are configured and healthy';

    /**
     * Warnings leave the exit code at zero on purpose. A local install with
     * no webhook secret yet is worth reporting but is not a broken one, and a
     * check that fails a deployment over it would just be switched off.
     */
    public function handle(HealthReport $report): int
    {
        $checks = $report->all();

        $this->newLine();
        $this->line('Laravel Cashier Inspector');
        $this->newLine();

        $checks->each(fn (HealthCheck $check) => match ($check->severity) {
            Severity::Error => $this->components->error($check->message),
            Severity::Warning => $this->components->warn($check->message),
            default => $this->components->info($check->message),
        });

        return $checks->contains(fn (HealthCheck $check) => $check->failed())
            ? self::FAILURE
            : self::SUCCESS;
    }
}
