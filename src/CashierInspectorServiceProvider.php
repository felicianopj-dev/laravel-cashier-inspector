<?php

namespace FelicianoPJ\CashierInspector;

use FelicianoPJ\CashierInspector\Console\CheckCommand;
use FelicianoPJ\CashierInspector\Console\EventCommand;
use FelicianoPJ\CashierInspector\Console\InstallCommand;
use FelicianoPJ\CashierInspector\Console\PruneCommand;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Http\Middleware\InstrumentCashierWebhook;
use FelicianoPJ\CashierInspector\Http\Middleware\RecordWebhookOutcome;
use FelicianoPJ\CashierInspector\Listeners\RecordWebhookHandled;
use FelicianoPJ\CashierInspector\Listeners\RecordWebhookReceived;
use FelicianoPJ\CashierInspector\Listeners\ReportPreHandledFailure;
use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;
use FelicianoPJ\CashierInspector\Support\StepRecorder;
use FelicianoPJ\CashierInspector\Support\TelescopeIntegration;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Http\Controllers\WebhookController;
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

        $this->app->singleton(StepRecorder::class);

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
                CheckCommand::class,
                EventCommand::class,
                InstallCommand::class,
                PruneCommand::class,
            ]);
        }

        $this->loadRoutesFrom($this->routesPath);

        $this->app->make(TelescopeIntegration::class)->register();
        $this->loadViewsFrom($this->viewsPath, 'cashier-inspector');

        Event::listen(WebhookReceived::class, RecordWebhookReceived::class);
        Event::listen(WebhookHandled::class, RecordWebhookHandled::class);

        $this->registerPreHandledFailureCapture();
        $this->registerRouteInstrumentation();
    }

    /**
     * Attach the opt-in middleware to Cashier's own webhook route.
     *
     * Off by default: the exception reporting hook and the terminating
     * middleware capture failures without touching anyone's routes. This is
     * for installations that want every Throwable recorded, reportable or
     * not, and signature verification measured rather than inferred.
     *
     * Routes are matched by the controller they resolve to, not by URI or
     * name, so relocating Cashier with CASHIER_PATH changes nothing here.
     * Deferred until the application has booted, since Cashier registers its
     * route from its own provider and the order between providers is not
     * ours to assume.
     */
    protected function registerRouteInstrumentation(): void
    {
        if (! config('cashier-inspector.integrations.route_middleware', false)) {
            return;
        }

        $this->app->booted(function () {
            foreach (Route::getRoutes() as $route) {
                try {
                    $controller = $route->getController();
                } catch (Throwable) {
                    continue;
                }

                if ($controller instanceof WebhookController) {
                    $route->middleware(InstrumentCashierWebhook::class);
                }
            }
        });
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
