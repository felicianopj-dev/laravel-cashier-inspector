<?php

namespace FelicianoPJ\CashierInspector\Diagnostics\Rules;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Applies to every event, the same way MissingWebhookSecretRule does: the
 * exposure is account-wide (Cashier's own migrations weren't run or are
 * outdated), not specific to any one delivery.
 */
class IncompatibleCashierSchemaRule implements DiagnosticRule
{
    protected const EXPECTED = [
        'subscriptions' => ['user_id', 'type', 'stripe_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at'],
        'subscription_items' => ['subscription_id', 'stripe_id', 'stripe_product', 'stripe_price', 'quantity'],
    ];

    public function supports(InspectorEvent $event): bool
    {
        return true;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $missing = $this->missingPieces();

        if ($missing->isEmpty()) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::error(
            code: 'cashier_schema_incompatible',
            title: 'Cashier\'s database schema looks incomplete',
            message: "Cashier's own tables are missing expected structure: {$missing->implode(', ')}.",
            suggestedChecks: [
                'Run `php artisan migrate` to apply Cashier\'s own migrations.',
                'Confirm the Cashier package version installed matches the migrations that have run.',
            ],
            context: [
                'missing' => $missing->all(),
            ],
        );
    }

    /**
     * Exposed publicly so cashier-inspector:install can reuse the same
     * check instead of duplicating it.
     */
    public function missingPieces()
    {
        $missing = collect();

        foreach (self::EXPECTED as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing->push("{$table} table");

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing->push("{$table}.{$column} column");
                }
            }
        }

        return $missing;
    }
}
