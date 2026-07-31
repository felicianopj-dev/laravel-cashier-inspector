<?php

namespace FelicianoPJ\CashierInspector;

use FelicianoPJ\CashierInspector\Listeners\RecordWebhookHandled;
use FelicianoPJ\CashierInspector\Listeners\RecordWebhookReceived;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

class CashierInspectorServiceProvider extends ServiceProvider
{
    protected string $configPath = __DIR__.'/../config/cashier-inspector.php';

    protected string $migrationsPath = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath, 'cashier-inspector');

        $this->app->singleton(WebhookCaptureContext::class);
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

        Event::listen(WebhookReceived::class, RecordWebhookReceived::class);
        Event::listen(WebhookHandled::class, RecordWebhookHandled::class);
    }
}
