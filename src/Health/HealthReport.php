<?php

namespace FelicianoPJ\CashierInspector\Health;

use FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;

/**
 * Everything cashier-inspector:check reports, with no console output of its
 * own so each check can be tested directly. The install command reuses the
 * checks it shares, so the two commands cannot drift apart.
 */
class HealthReport
{
    protected const DEFAULT_RECENT_EVENTS_WINDOW_HOURS = 24;

    /**
     * @return \Illuminate\Support\Collection<int, HealthCheck>
     */
    public function all(): Collection
    {
        $checks = collect([
            $this->cashierInstalled(),
            $this->cashierSchema(),
            $ownSchema = $this->ownSchema(),
            $this->stripeSecret(),
            $this->webhookSecret(),
            $this->billableModel(),
        ]);

        // The last two checks read this package's own tables, so there is
        // nothing to say until those exist - and asking would be a SQL error
        // rather than a report.
        if ($ownSchema->failed()) {
            return $checks;
        }

        return $checks->push($this->recentEvents(), $this->recordedFindings());
    }

    public function cashierInstalled(): HealthCheck
    {
        if (class_exists(Cashier::class)) {
            return HealthCheck::pass('Laravel Cashier Stripe is installed.');
        }

        return HealthCheck::error(
            'Laravel Cashier Stripe was not found. Install it with `composer require laravel/cashier`.'
        );
    }

    public function cashierSchema(): HealthCheck
    {
        $missing = (new IncompatibleCashierSchemaRule)->missingPieces();

        if ($missing->isEmpty()) {
            return HealthCheck::pass('Cashier\'s database schema looks complete.');
        }

        // Cashier only publishes its migrations, it does not load them, so
        // migrate alone never creates these tables.
        return HealthCheck::error(
            "Cashier's database schema is missing: {$missing->implode(', ')}. "
            .'Run `php artisan vendor:publish --tag=cashier-migrations`, then `php artisan migrate`.'
        );
    }

    public function ownSchema(): HealthCheck
    {
        $missing = collect([
            (new InspectorEvent)->getTable(),
            (new InspectorDelivery)->getTable(),
            (new InspectorDiagnostic)->getTable(),
        ])->reject(fn (string $table) => Schema::hasTable($table));

        if ($missing->isEmpty()) {
            return HealthCheck::pass('Cashier Inspector\'s own tables exist.');
        }

        return HealthCheck::error(
            "Cashier Inspector's own tables are missing: {$missing->implode(', ')}. "
            .'Run `php artisan cashier-inspector:install`, then `php artisan migrate`.'
        );
    }

    public function stripeSecret(): HealthCheck
    {
        if (filled(config('cashier.secret'))) {
            return HealthCheck::pass('STRIPE_SECRET is configured.');
        }

        return HealthCheck::error(
            'STRIPE_SECRET is not set. Cashier cannot talk to Stripe without it.'
        );
    }

    public function webhookSecret(): HealthCheck
    {
        if (filled(config('cashier.webhook.secret'))) {
            return HealthCheck::pass('STRIPE_WEBHOOK_SECRET is configured.');
        }

        return HealthCheck::warn(
            'STRIPE_WEBHOOK_SECRET is not set. Without it, Cashier cannot verify '
            .'incoming webhook requests came from Stripe.'
        );
    }

    /**
     * Cashier resolves every customer through one configured model, so the
     * useful question is not whether the class exists but whether it is
     * actually billable - a class without the trait resolves to nothing and
     * every event ends up with no local model attached.
     */
    public function billableModel(): HealthCheck
    {
        $model = Cashier::$customerModel;

        if (! class_exists($model)) {
            return HealthCheck::warn(
                "Cashier's customer model [{$model}] does not exist. Set it with "
                .'Cashier::useCustomerModel() in a service provider.'
            );
        }

        if (! in_array(Billable::class, class_uses_recursive($model), true)) {
            return HealthCheck::warn(
                "Cashier's customer model [{$model}] does not use the Billable trait, "
                .'so no event can be matched to a local model.'
            );
        }

        return HealthCheck::pass("Billable model was detected [{$model}].");
    }

    public function recentEvents(): HealthCheck
    {
        $hours = $this->recentEventsWindowHours();
        $window = Carbon::now()->subHours($hours);

        $received = InspectorEvent::query()->where('created_at', '>=', $window)->count();

        if ($received > 0) {
            return HealthCheck::pass("{$received} webhook events were received in the last {$hours} hours.");
        }

        return HealthCheck::warn(
            "No webhook events were received in the last {$hours} hours. "
            .'Check that Stripe is pointed at Cashier\'s webhook route.'
        );
    }

    /**
     * What the diagnostic rules have already found, rather than a fresh
     * comparison. Findings from rules that describe the installation are
     * excluded because the checks above report those conditions directly,
     * and they would otherwise be counted twice.
     */
    public function recordedFindings(): HealthCheck
    {
        $findings = InspectorDiagnostic::query()
            ->whereIn('severity', [Severity::Warning->value, Severity::Error->value])
            ->whereNotIn('rule', InspectorDelivery::environmentRuleClasses())
            ->get();

        if ($findings->isEmpty()) {
            return HealthCheck::pass('No problems were diagnosed on the events that are still stored.');
        }

        $summary = $findings->countBy('code')
            ->map(fn (int $count, string $code) => "{$count} {$code}")
            ->implode(', ');

        $message = "Diagnosed on the events that are still stored: {$summary}. "
            .'Open the dashboard for the full report.';

        return $findings->contains(fn (InspectorDiagnostic $finding) => $finding->severity === Severity::Error)
            ? HealthCheck::error($message)
            : HealthCheck::warn($message);
    }

    protected function recentEventsWindowHours(): int
    {
        $hours = (int) config(
            'cashier-inspector.health.recent_events_window_hours',
            self::DEFAULT_RECENT_EVENTS_WINDOW_HOURS
        );

        return $hours > 0 ? $hours : self::DEFAULT_RECENT_EVENTS_WINDOW_HOURS;
    }
}
