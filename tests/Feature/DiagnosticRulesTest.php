<?php

use FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateDeliveryRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingBillableModelRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingLocalSubscriptionRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\ProcessingExceptionRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\SlowProcessingRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\TestLiveModeMismatchRule;
use FelicianoPJ\CashierInspector\Diagnostics\Rules\UnhandledWebhookRule;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Subscription;

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

// MissingLocalSubscriptionRule

it('warns when the event subscription id has no matching local subscription', function () use ($makeEvent) {
    $event = $makeEvent(['subscription_id' => 'sub_missing']);

    $rule = new MissingLocalSubscriptionRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('missing_local_subscription')
        ->and($result->context)->toBe(['subscription_id' => 'sub_missing']);
});

it('passes when the event subscription id has a matching local subscription', function () use ($makeEvent) {
    $user = \Workbench\App\Models\User::create([
        'name' => 'Sub Owner',
        'email' => 'sub-owner@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_present',
        'stripe_status' => 'active',
    ]);

    $result = (new MissingLocalSubscriptionRule)->diagnose($makeEvent(['subscription_id' => 'sub_present']));

    expect($result->isTriggered())->toBeFalse();
});

it('does not support an event without a subscription id', function () use ($makeEvent) {
    expect((new MissingLocalSubscriptionRule)->supports($makeEvent()))->toBeFalse();
});

// IncompatibleCashierSchemaRule

it('passes when Cashier\'s schema has the expected tables and columns', function () use ($makeEvent) {
    $result = (new IncompatibleCashierSchemaRule)->diagnose($makeEvent());

    expect($result->isTriggered())->toBeFalse();
});

it('flags a missing Cashier table', function () use ($makeEvent) {
    Illuminate\Support\Facades\Schema::drop('subscription_items');

    $result = (new IncompatibleCashierSchemaRule)->diagnose($makeEvent());

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('cashier_schema_incompatible')
        ->and($result->context['missing'])->toContain('subscription_items table');
});

// SlowProcessingRule

it('warns when processing duration exceeds the configured threshold', function () use ($makeEvent) {
    config()->set('cashier-inspector.diagnostics.slow_processing_threshold_ms', 1000);

    $event = $makeEvent();
    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'duration_ms' => 2500,
    ]);
    $event->refresh();

    $rule = new SlowProcessingRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('slow_processing')
        ->and($result->context)->toBe(['duration_ms' => 2500, 'threshold_ms' => 1000]);
});

it('passes when processing duration is within the threshold', function () use ($makeEvent) {
    config()->set('cashier-inspector.diagnostics.slow_processing_threshold_ms', 5000);

    $event = $makeEvent();
    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'duration_ms' => 120,
    ]);
    $event->refresh();

    expect((new SlowProcessingRule)->diagnose($event)->isTriggered())->toBeFalse();
});

it('does not support a delivery without a recorded duration', function () use ($makeEvent) {
    $event = $makeEvent();
    $event->deliveries()->create(['status' => EventStatus::Received, 'received_at' => now()]);
    $event->refresh();

    expect((new SlowProcessingRule)->supports($event))->toBeFalse();
});

// MissingBillableModelRule

it('warns when the event customer id has no resolved local billable model', function () use ($makeEvent) {
    $event = $makeEvent(['customer_id' => 'cus_no_billable']);

    $rule = new MissingBillableModelRule;

    expect($rule->supports($event))->toBeTrue();

    $result = $rule->diagnose($event);

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('missing_billable_model')
        ->and($result->context)->toBe(['customer_id' => 'cus_no_billable']);
});

it('passes when the event has a resolved local billable model', function () use ($makeEvent) {
    $event = $makeEvent([
        'customer_id' => 'cus_has_billable',
        'billable_type' => 'Workbench\\App\\Models\\User',
        'billable_id' => 1,
    ]);

    expect((new MissingBillableModelRule)->diagnose($event)->isTriggered())->toBeFalse();
});

it('does not support an event without a customer id', function () use ($makeEvent) {
    expect((new MissingBillableModelRule)->supports($makeEvent()))->toBeFalse();
});
