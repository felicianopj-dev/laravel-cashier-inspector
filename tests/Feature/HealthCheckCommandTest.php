<?php

use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\SlowProcessingRule;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Health\HealthReport;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;

/**
 * A healthy baseline, so each test only has to break the one thing it is
 * about. Without the keys set every run would fail on the Stripe checks.
 */
beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_health');
    config()->set('cashier.webhook.secret', 'whsec_health');
    Cashier::useCustomerModel(Workbench\App\Models\User::class);
});

afterEach(function () {
    Cashier::useCustomerModel('App\Models\User');
});

$makeEvent = function (string $id = 'evt_health'): InspectorEvent {
    return InspectorEvent::create([
        'stripe_event_id' => $id,
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);
};

$makeFinding = function (InspectorEvent $event, string $rule, string $code, Severity $severity): InspectorDiagnostic {
    return InspectorDiagnostic::create([
        'event_id' => $event->id,
        'rule' => $rule,
        'code' => $code,
        'severity' => $severity,
        'title' => 'Something was found',
        'message' => 'Details about what was found.',
        'created_at' => now(),
    ]);
};

it('passes every check and succeeds on a healthy install', function () use ($makeEvent) {
    $makeEvent();

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('Laravel Cashier Stripe is installed.')
        ->expectsOutputToContain('STRIPE_SECRET is configured.')
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET is configured.')
        ->expectsOutputToContain('Billable model was detected')
        ->expectsOutputToContain('No problems were diagnosed')
        ->assertSuccessful();
});

it('fails when the Stripe secret is missing', function () {
    config()->set('cashier.secret', null);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('STRIPE_SECRET is not set.')
        ->assertFailed();
});

it('succeeds when the only problems are warnings', function () use ($makeEvent) {
    $makeEvent();
    config()->set('cashier.webhook.secret', null);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET is not set.')
        ->assertSuccessful();
});

it('warns when no events arrived inside the window', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->forceFill(['created_at' => now()->subDays(3)])->save();

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('No webhook events were received in the last 24 hours.')
        ->assertSuccessful();
});

it('honours a configured recent events window', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->forceFill(['created_at' => now()->subDays(3)])->save();

    config()->set('cashier-inspector.health.recent_events_window_hours', 96);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('1 webhook events were received in the last 96 hours.')
        ->assertSuccessful();
});

it('falls back to 24 hours when the configured window is not a positive number', function () {
    config()->set('cashier-inspector.health.recent_events_window_hours', 0);

    expect((new HealthReport)->recentEvents()->message)->toContain('last 24 hours');
});

it('counts diagnosed problems and fails on an error finding', function () use ($makeEvent, $makeFinding) {
    $event = $makeEvent();
    $makeFinding($event, SlowProcessingRule::class, 'slow_processing', Severity::Error);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('1 slow_processing')
        ->assertFailed();
});

it('succeeds when the diagnosed problems are only warnings', function () use ($makeEvent, $makeFinding) {
    $event = $makeEvent();
    $makeFinding($event, SlowProcessingRule::class, 'slow_processing', Severity::Warning);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('1 slow_processing')
        ->assertSuccessful();
});

it('ignores findings from rules that describe the installation', function () use ($makeEvent, $makeFinding) {
    $event = $makeEvent();
    $makeFinding($event, MissingWebhookSecretRule::class, 'webhook_secret_missing', Severity::Error);

    $this->artisan('cashier-inspector:check')
        ->expectsOutputToContain('No problems were diagnosed')
        ->assertSuccessful();
});

it('warns when Cashier\'s customer model is not billable', function () {
    Cashier::useCustomerModel(Illuminate\Support\Fluent::class);

    expect((new HealthReport)->billableModel())
        ->severity->toBe(Severity::Warning)
        ->message->toContain('does not use the Billable trait');
});

it('warns when Cashier\'s customer model does not exist', function () {
    Cashier::useCustomerModel('App\Models\NoSuchCustomer');

    expect((new HealthReport)->billableModel())
        ->severity->toBe(Severity::Warning)
        ->message->toContain('does not exist');
});

it('fails and skips the event checks when its own tables are missing', function () {
    Schema::drop('cashier_inspector_diagnostics');

    $checks = (new HealthReport)->all();

    expect($checks->contains(fn ($check) => str_contains($check->message, 'cashier_inspector_diagnostics')))->toBeTrue()
        ->and($checks->contains(fn ($check) => str_contains($check->message, 'webhook events were received')))->toBeFalse()
        ->and($checks->contains(fn ($check) => str_contains($check->message, 'diagnosed')))->toBeFalse();

    $this->artisan('cashier-inspector:check')->assertFailed();
});
