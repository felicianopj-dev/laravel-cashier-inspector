<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Health\HealthCheck;
use FelicianoPJ\CashierInspector\Health\HealthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class InstallCommand extends Command
{
    protected $signature = 'cashier-inspector:install';

    protected $description = 'Publish Cashier Inspector\'s config and migrations, and check the install is ready to use';

    /**
     * The checks reported here are the health report's, so the two commands
     * cannot describe the same condition differently. Install stays advisory
     * and always succeeds - cashier-inspector:check is the one that fails.
     */
    public function handle(HealthReport $report): int
    {
        $this->call('vendor:publish', [
            '--provider' => CashierInspectorServiceProvider::class,
            '--tag' => 'cashier-inspector-config',
        ]);

        $this->publishMigrations();

        $this->newLine();

        foreach ([$report->cashierInstalled(), $report->cashierSchema(), $report->webhookSecret()] as $check) {
            $this->report($check);
        }

        $this->explainDashboardAuthorization();

        return self::SUCCESS;
    }

    protected function report(HealthCheck $check): void
    {
        $check->severity === Severity::Success
            ? $this->components->info($check->message)
            : $this->components->warn($check->message);
    }

    /**
     * Publish the migrations, unless a previous run already did.
     *
     * vendor:publish stamps a fresh timestamp onto every migration it copies
     * and does not look for an earlier copy, so publishing twice leaves two
     * migrations creating the same table and the next migrate run fails. This
     * command is meant to be re-run after fixing what it reports, so it has to
     * recognise its own earlier output. Names are compared without the leading
     * timestamp, which is the only part publishing changes.
     */
    protected function publishMigrations(): void
    {
        $published = $this->migrationNames($this->laravel->databasePath('migrations'))
            ->intersect($this->migrationNames(__DIR__.'/../../database/migrations'));

        if ($published->isNotEmpty()) {
            $this->components->info('Migrations are already published.');

            return;
        }

        $this->call('vendor:publish', [
            '--provider' => CashierInspectorServiceProvider::class,
            '--tag' => 'cashier-inspector-migrations',
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function migrationNames(string $directory): Collection
    {
        return collect(glob($directory.'/*.php') ?: [])
            ->map(fn (string $path) => preg_replace(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php')
            ));
    }

    protected function explainDashboardAuthorization(): void
    {
        $this->newLine();
        $this->components->info('Dashboard: '.url(config('cashier-inspector.path')));

        $this->line(
            'The dashboard is enabled by default only in local environments and is '
            .'never public in production without explicit opt-in. Configure who can '
            .'view it in production with CashierInspector::auth(), in a service '
            .'provider\'s boot() method, e.g.:'
        );

        $this->newLine();
        $this->line('    CashierInspector::auth(fn ($request) => $request->user()?->isAdmin());');
    }
}
