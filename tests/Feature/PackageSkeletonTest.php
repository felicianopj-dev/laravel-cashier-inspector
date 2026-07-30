<?php

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;

it('boots the Testbench application with the package provider registered', function () {
    expect(app()->getProviders(CashierInspectorServiceProvider::class))->not->toBeEmpty();
});
