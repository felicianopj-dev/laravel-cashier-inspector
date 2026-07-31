<?php

use FelicianoPJ\CashierInspector\CashierInspector;
use FelicianoPJ\CashierInspector\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    CashierInspector::flushState();
});

$protectDashboardTestRoute = function (): void {
    Route::middleware(Authorize::class)->get('_test/cashier-inspector', fn () => 'ok');
};

it('responds 404 when the dashboard is disabled, regardless of authorization', function () use ($protectDashboardTestRoute) {
    config()->set('cashier-inspector.enabled', false);
    CashierInspector::auth(fn () => true);

    $protectDashboardTestRoute();

    $this->get('_test/cashier-inspector')->assertNotFound();
});

it('allows access by default when the app is in the local environment', function () use ($protectDashboardTestRoute) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $protectDashboardTestRoute();

    $this->get('_test/cashier-inspector')->assertOk();
});

it('denies access by default outside the local environment with no custom callback', function () use ($protectDashboardTestRoute) {
    config()->set('cashier-inspector.enabled', true);

    $protectDashboardTestRoute();

    $this->get('_test/cashier-inspector')->assertForbidden();
});

it('grants access when a custom authorization callback returns true', function () use ($protectDashboardTestRoute) {
    config()->set('cashier-inspector.enabled', true);
    CashierInspector::auth(fn () => true);

    $protectDashboardTestRoute();

    $this->get('_test/cashier-inspector')->assertOk();
});

it('denies access when a custom authorization callback returns false', function () use ($protectDashboardTestRoute) {
    config()->set('cashier-inspector.enabled', true);
    CashierInspector::auth(fn () => false);

    $protectDashboardTestRoute();

    $this->get('_test/cashier-inspector')->assertForbidden();
});
