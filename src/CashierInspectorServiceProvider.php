<?php

namespace FelicianoPJ\CashierInspector;

use Illuminate\Support\ServiceProvider;

class CashierInspectorServiceProvider extends ServiceProvider
{
    protected string $configPath = __DIR__.'/../config/cashier-inspector.php';

    protected string $migrationsPath = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath, 'cashier-inspector');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath => $this->app->configPath('cashier-inspector.php'),
            ], 'cashier-inspector-config');

            $this->publishesMigrations([
                $this->migrationsPath => $this->app->databasePath('migrations'),
            ], 'cashier-inspector-migrations');
        }
    }
}
