<?php

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;

it('merges the package config', function () {
    expect(config('cashier-inspector.path'))->toBe('cashier-inspector')
        ->and(config('cashier-inspector.middleware'))->toBe(['web'])
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
