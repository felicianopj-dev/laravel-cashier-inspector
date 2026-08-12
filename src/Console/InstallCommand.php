<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class InstallCommand extends Command
{
    protected $signature = 'cashier-inspector:install';

    protected $description = 'Publish Cashier Inspector\'s config and migrations, and check the install is ready to use';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--provider' => CashierInspectorServiceProvider::class,
            '--tag' => 'cashier-inspector-config',
        ]);

        $this->publishMigrations();

        $this->newLine();
        $this->checkCashierIsInstalled();
        $this->checkCashierSchema();
        $this->checkWebhookSecret();
        $this->explainDashboardAuthorization();

        return self::SUCCESS;
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

    protected function checkCashierIsInstalled(): void
    {
        if (class_exists(\Laravel\Cashier\Cashier::class)) {
            $this->components->info('Laravel Cashier Stripe is installed.');

            return;
        }

        $this->components->warn('Laravel Cashier Stripe was not found. Install it with `composer require laravel/cashier`.');
    }

    protected function checkCashierSchema(): void
    {
        $missing = (new IncompatibleCashierSchemaRule)->missingPieces();

        if ($missing->isEmpty()) {
            $this->components->info('Cashier\'s database schema looks complete.');

            return;
        }

        // Cashier only publishes its migrations, it does not load them, so
        // migrate alone never creates these tables.
        $this->components->warn(
            "Cashier's database schema is missing: {$missing->implode(', ')}. "
            .'Run `php artisan vendor:publish --tag=cashier-migrations`, then `php artisan migrate`.'
        );
    }

    protected function checkWebhookSecret(): void
    {
        if (filled(config('cashier.webhook.secret'))) {
            $this->components->info('STRIPE_WEBHOOK_SECRET is configured.');

            return;
        }

        $this->components->warn('STRIPE_WEBHOOK_SECRET is not set. Without it, Cashier cannot verify incoming webhook requests came from Stripe.');
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
