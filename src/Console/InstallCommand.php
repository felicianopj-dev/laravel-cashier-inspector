<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Health\HealthCheck;
use FelicianoPJ\CashierInspector\Health\HealthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
     * Publish whichever migrations the application doesn't have yet.
     *
     * vendor:publish stamps a fresh timestamp onto every migration it copies
     * and does not look for an earlier copy, so letting it publish twice
     * leaves two migrations creating the same table and the next migrate run
     * fails. This command is meant to be re-run after fixing what it reports,
     * so it has to recognise its own earlier output - and a release that adds
     * a table has to reach an application that published the earlier ones,
     * which is why this compares file by file rather than skipping wholesale.
     * Names are compared without the leading timestamp, the only part
     * publishing changes.
     */
    protected function publishMigrations(): void
    {
        $target = $this->laravel->databasePath('migrations');
        $existing = $this->migrationNames($target);

        $missing = collect(glob(__DIR__.'/../../database/migrations/*.php') ?: [])
            ->reject(fn (string $path) => $existing->contains($this->migrationName($path)));

        if ($missing->isEmpty()) {
            $this->components->info('Migrations are already published.');

            return;
        }

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        // Ordered so migrations that were written to run in sequence keep
        // that order under their new timestamps.
        foreach ($missing->sort()->values() as $index => $path) {
            $name = Carbon::now()->addSeconds($index)->format('Y_m_d_His').'_'.$this->migrationName($path).'.php';

            copy($path, $target.'/'.$name);

            $this->components->info("Published migration [{$name}].");
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function migrationNames(string $directory): Collection
    {
        return collect(glob($directory.'/*.php') ?: [])
            ->map(fn (string $path) => $this->migrationName($path));
    }

    protected function migrationName(string $path): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
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
