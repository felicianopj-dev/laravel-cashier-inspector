<?php

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateDeliveryRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateSubscriptionTypeRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingBillableModelRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingLocalSubscriptionRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\ProcessingExceptionRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\SlowProcessingRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\SubscriptionPriceMismatchRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\SubscriptionStatusMismatchRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\TestLiveModeMismatchRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\UnhandledWebhookRule;

it('merges the package config', function () {
    expect(config('cashier-inspector.path'))->toBe('cashier-inspector')
        ->and(config('cashier-inspector.middleware'))->toBe(['web'])
        ->and(config('cashier-inspector.polling.enabled'))->toBeTrue()
        ->and(config('cashier-inspector.polling.interval_ms'))->toBe(5000)
        ->and(config('cashier-inspector.diagnostics.rules'))->toBe([
            MissingWebhookSecretRule::class,
            ProcessingExceptionRule::class,
            UnhandledWebhookRule::class,
            DuplicateDeliveryRule::class,
            TestLiveModeMismatchRule::class,
            MissingLocalSubscriptionRule::class,
            IncompatibleCashierSchemaRule::class,
            SlowProcessingRule::class,
            MissingBillableModelRule::class,
            DuplicateSubscriptionTypeRule::class,
            SubscriptionStatusMismatchRule::class,
            SubscriptionPriceMismatchRule::class,
        ])
        ->and(config('cashier-inspector.diagnostics.slow_processing_threshold_ms'))->toBe(5000)
        ->and(config('cashier-inspector.stripe_api_checks.enabled'))->toBeFalse()
        ->and(config('cashier-inspector.stripe_api_checks.timeout_seconds'))->toBe(5)
        ->and(config('cashier-inspector.redaction.enabled'))->toBeTrue()
        ->and(config('cashier-inspector.storage.retention_days'))->toBe(7);
});

it('disables the dashboard and payload storage by default outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(config('cashier-inspector.enabled'))->toBeFalse()
        ->and(config('cashier-inspector.storage.store_payloads'))->toBeFalse();
});

it('registers the publishable config file', function () {
    $publishes = CashierInspectorServiceProvider::pathsToPublish(
        CashierInspectorServiceProvider::class,
        'cashier-inspector-config'
    );

    expect($publishes)->toHaveCount(1)
        ->and(array_values($publishes)[0])->toBe(config_path('cashier-inspector.php'));
});
