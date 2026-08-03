<?php

namespace FelicianoPJ\CashierInspector\Console;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule;
use Illuminate\Console\Command;

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

        $this->call('vendor:publish', [
            '--provider' => CashierInspectorServiceProvider::class,
            '--tag' => 'cashier-inspector-migrations',
        ]);

        $this->newLine();
        $this->checkCashierIsInstalled();
        $this->checkCashierSchema();
        $this->checkWebhookSecret();
        $this->explainDashboardAuthorization();

        return self::SUCCESS;
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

        $this->components->warn("Cashier's database schema is missing: {$missing->implode(', ')}. Run `php artisan migrate`.");
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
