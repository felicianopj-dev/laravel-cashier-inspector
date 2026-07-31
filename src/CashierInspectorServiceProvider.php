<?php

namespace FelicianoPJ\CashierInspector;

use FelicianoPJ\CashierInspector\Console\PruneCommand;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Http\Middleware\RecordWebhookOutcome;
use FelicianoPJ\CashierInspector\Listeners\RecordWebhookHandled;
use FelicianoPJ\CashierInspector\Listeners\RecordWebhookReceived;
use FelicianoPJ\CashierInspector\Listeners\ReportPreHandledFailure;
use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

class CashierInspectorServiceProvider extends ServiceProvider
{
    protected string $configPath = __DIR__.'/../config/cashier-inspector.php';

    protected string $migrationsPath = __DIR__.'/../database/migrations';

    protected string $routesPath = __DIR__.'/../routes/web.php';

    protected string $viewsPath = __DIR__.'/../resources/views';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath, 'cashier-inspector');

        $this->app->singleton(WebhookCaptureContext::class);

        $this->app->singleton(PayloadRedactor::class, fn ($app) => new PayloadRedactor(
            paths: $app['config']->get('cashier-inspector.redaction.paths', []),
            enabled: $app['config']->get('cashier-inspector.redaction.enabled', true),
        ));

        $this->app->singleton(DiagnosticEngine::class, fn ($app) => new DiagnosticEngine(
            rules: array_map(
                fn (string $rule) => $app->make($rule),
                $app['config']->get('cashier-inspector.diagnostics.rules', [])
            ),
        ));
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

            $this->commands([
                PruneCommand::class,
            ]);
        }

        $this->loadRoutesFrom($this->routesPath);
        $this->loadViewsFrom($this->viewsPath, 'cashier-inspector');

        Event::listen(WebhookReceived::class, RecordWebhookReceived::class);
        Event::listen(WebhookHandled::class, RecordWebhookHandled::class);

        $this->registerPreHandledFailureCapture();
    }

    protected function registerPreHandledFailureCapture(): void
    {
        $exceptionHandler = $this->app->make(ExceptionHandler::class);

        if (method_exists($exceptionHandler, 'reportable')) {
            $exceptionHandler->reportable(
                fn (Throwable $e) => $this->app->make(ReportPreHandledFailure::class)($e)
            );
        }

        $httpKernel = $this->app->make(HttpKernel::class);

        if (method_exists($httpKernel, 'pushMiddleware')) {
            $httpKernel->pushMiddleware(RecordWebhookOutcome::class);
        }
    }
}
