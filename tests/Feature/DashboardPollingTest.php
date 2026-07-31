<?php

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';
});

it('serves the vendored Alpine.js build with a javascript content type', function () {
    $response = $this->get('cashier-inspector/assets/alpine.min.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript');
});

it('responds 404 for the asset route when the dashboard is disabled', function () {
    config()->set('cashier-inspector.enabled', false);

    $this->get('cashier-inspector/assets/alpine.min.js')->assertNotFound();
});

it('renders the polling config and the Alpine script tag on the dashboard', function () {
    config()->set('cashier-inspector.polling.interval_ms', 7000);

    $response = $this->get('cashier-inspector')->assertOk();

    $response->assertSee('intervalMs: 7000', false)
        ->assertSee('autoRefresh: true', false)
        ->assertSee(route('cashier-inspector.assets.alpine'), false)
        ->assertSee('dashboardPolling(', false);
});

it('clamps a too-low polling interval to the configured minimum', function () {
    config()->set('cashier-inspector.polling.interval_ms', 100);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('intervalMs: 2000', false);
});
