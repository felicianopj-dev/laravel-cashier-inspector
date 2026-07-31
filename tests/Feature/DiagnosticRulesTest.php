<?php

use FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateDeliveryRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\ProcessingExceptionRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\TestLiveModeMismatchRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\UnhandledWebhookRule;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

$makeEvent = function (array $overrides = []): InspectorEvent {
    static $counter = 0;
    $counter++;

    return InspectorEvent::create(array_merge([
        'stripe_event_id' => "evt_rule_{$counter}",
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ], $overrides));
};

// MissingWebhookSecretRule

it('warns when the webhook secret is missing', function () use ($makeEvent) {
    config()->set('cashier.webhook.secret', null);

    $result = (new MissingWebhookSecretRule)->diagnose($makeEvent());

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('webhook_secret_missing');
});

it('passes when the webhook secret is configured', function () use ($makeEvent) {
    config()->set('cashier.webhook.secret', 'whsec_test');

    $result = (new MissingWebhookSecretRule)->diagnose($makeEvent());

    expect($result->isTriggered())->toBeFalse();
});

// ProcessingExceptionRule

it('supports and diagnoses a failed delivery with an exception', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create([
        'status' => EventStatus::Failed,
        'received_at' => now(),
        'exception_class' => 'RuntimeException',
        'exception_message' => 'Boom.',
    ]);
    $event->refresh();

    $rule = new ProcessingExceptionRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('processing_exception')
        ->and($result->context['exception_class'])->toBe('RuntimeException');
});

it('does not support a successfully handled delivery', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
    ]);
    $event->refresh();

    expect((new ProcessingExceptionRule)->supports($event))->toBeFalse();
});

// UnhandledWebhookRule

it('supports and diagnoses an unmatched delivery', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create([
        'status' => EventStatus::Unmatched,
        'severity' => Severity::Info,
        'received_at' => now(),
    ]);
    $event->refresh();

    $rule = new UnhandledWebhookRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('webhook_unmatched');
});

// DuplicateDeliveryRule

it('supports and warns when an event has more than one delivery', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create(['status' => EventStatus::Failed, 'received_at' => now()->subMinute()]);
    $event->deliveries()->create(['status' => EventStatus::Handled, 'severity' => Severity::Success, 'received_at' => now()]);
    $event->refresh();

    $rule = new DuplicateDeliveryRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('duplicate_delivery')
        ->and($result->context['delivery_count'])->toBe(2);
});

it('does not support an event with a single delivery', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create(['status' => EventStatus::Received, 'received_at' => now()]);
    $event->refresh();

    expect((new DuplicateDeliveryRule)->supports($event))->toBeFalse();
});

// TestLiveModeMismatchRule

it('warns when the event mode does not match the configured Stripe key', function () use ($makeEvent) {
    config()->set('cashier.secret', 'sk_live_abc');

    $result = (new TestLiveModeMismatchRule)->diagnose($makeEvent(['livemode' => false]));

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('mode_mismatch')
        ->and($result->context)->toBe(['event_mode' => 'test', 'configured_mode' => 'live']);
});

it('passes when the event mode matches the configured Stripe key', function () use ($makeEvent) {
    config()->set('cashier.secret', 'sk_test_abc');

    $result = (new TestLiveModeMismatchRule)->diagnose($makeEvent(['livemode' => false]));

    expect($result->isTriggered())->toBeFalse();
});

it('does not support when no Stripe key is configured', function () use ($makeEvent) {
    config()->set('cashier.secret', null);

    expect((new TestLiveModeMismatchRule)->supports($makeEvent()))->toBeFalse();
});
