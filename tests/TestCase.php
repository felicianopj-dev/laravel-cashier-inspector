<?php

namespace FelicianoPJ\CashierInspector\Tests;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use Illuminate\Routing\Router;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CashierInspectorServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('cashier/webhook', [WebhookController::class, 'handleWebhook']);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
