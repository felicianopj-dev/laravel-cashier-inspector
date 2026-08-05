<?php

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
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;

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

// DuplicateSubscriptionTypeRule

it('passes when a single valid subscription exists for a type', function () use ($makeEvent) {
    $user = \Workbench\App\Models\User::create([
        'name' => 'Solo Sub',
        'email' => 'solo-sub@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_solo',
        'stripe_status' => 'active',
    ]);

    $result = (new DuplicateSubscriptionTypeRule)->diagnose($makeEvent(['subscription_id' => 'sub_solo']));

    expect($result->isTriggered())->toBeFalse();
});

it('warns when two active subscriptions share the same type', function () use ($makeEvent) {
    $user = \Workbench\App\Models\User::create([
        'name' => 'Dup Sub',
        'email' => 'dup-sub@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_dup_1',
        'stripe_status' => 'active',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_dup_2',
        'stripe_status' => 'active',
    ]);

    $result = (new DuplicateSubscriptionTypeRule)->diagnose($makeEvent(['subscription_id' => 'sub_dup_1']));

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('duplicate_subscription_type')
        ->and($result->context['type'])->toBe('default')
        ->and($result->context['subscription_ids'])->toEqualCanonicalizing(['sub_dup_1', 'sub_dup_2']);
});

it('does not count a canceled subscription as a duplicate', function () use ($makeEvent) {
    $user = \Workbench\App\Models\User::create([
        'name' => 'Resub',
        'email' => 'resub@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_old_canceled',
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDay(),
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_new_active',
        'stripe_status' => 'active',
    ]);

    $result = (new DuplicateSubscriptionTypeRule)->diagnose($makeEvent(['subscription_id' => 'sub_new_active']));

    expect($result->isTriggered())->toBeFalse();
});

it('does not treat different subscription types as duplicates', function () use ($makeEvent) {
    $user = \Workbench\App\Models\User::create([
        'name' => 'Multi Type',
        'email' => 'multi-type@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_type_a',
        'stripe_status' => 'active',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'swimming',
        'stripe_id' => 'sub_type_b',
        'stripe_status' => 'active',
    ]);

    $result = (new DuplicateSubscriptionTypeRule)->diagnose($makeEvent(['subscription_id' => 'sub_type_a']));

    expect($result->isTriggered())->toBeFalse();
});

it('passes when the event subscription id has no matching local subscription', function () use ($makeEvent) {
    $result = (new DuplicateSubscriptionTypeRule)->diagnose($makeEvent(['subscription_id' => 'sub_unknown']));

    expect($result->isTriggered())->toBeFalse();
});

it('does not support a duplicate-type check without a subscription id', function () use ($makeEvent) {
    expect((new DuplicateSubscriptionTypeRule)->supports($makeEvent()))->toBeFalse();
});

// SubscriptionStatusMismatchRule

it('does not support a status check when stripe_api_checks is disabled', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', false);

    expect((new SubscriptionStatusMismatchRule)->supports($makeEvent(['subscription_id' => 'sub_1'])))->toBeFalse();
});

it('supports a status check when stripe_api_checks is enabled and a subscription id is present', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    expect((new SubscriptionStatusMismatchRule)->supports($makeEvent(['subscription_id' => 'sub_1'])))->toBeTrue();
});

it('passes when the local and live subscription status match', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $user = \Workbench\App\Models\User::create([
        'name' => 'Status Match',
        'email' => 'status-match@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_status_match',
        'stripe_status' => 'active',
    ]);

    $rule = new class extends SubscriptionStatusMismatchRule
    {
        protected function liveSubscription(Subscription $subscription): StripeSubscription
        {
            return StripeSubscription::constructFrom(['id' => $subscription->stripe_id, 'status' => 'active']);
        }
    };

    $result = $rule->diagnose($makeEvent(['subscription_id' => 'sub_status_match']));

    expect($result->isTriggered())->toBeFalse();
});

it('warns when the local and live subscription status differ', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $user = \Workbench\App\Models\User::create([
        'name' => 'Status Mismatch',
        'email' => 'status-mismatch@example.com',
        'password' => 'secret',
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_status_mismatch',
        'stripe_status' => 'active',
    ]);

    $rule = new class extends SubscriptionStatusMismatchRule
    {
        protected function liveSubscription(Subscription $subscription): StripeSubscription
        {
            return StripeSubscription::constructFrom(['id' => $subscription->stripe_id, 'status' => 'canceled']);
        }
    };

    $result = $rule->diagnose($makeEvent(['subscription_id' => 'sub_status_mismatch']));

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('subscription_status_mismatch')
        ->and($result->context)->toBe([
            'subscription_id' => 'sub_status_mismatch',
            'local_status_as_of_now' => 'active',
            'stripe_status_as_of_now' => 'canceled',
        ]);
});

it('passes a status check when the event subscription id has no local match', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $result = (new SubscriptionStatusMismatchRule)->diagnose($makeEvent(['subscription_id' => 'sub_unknown_status']));

    expect($result->isTriggered())->toBeFalse();
});

// SubscriptionPriceMismatchRule

it('does not support a price check when stripe_api_checks is disabled', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', false);

    expect((new SubscriptionPriceMismatchRule)->supports($makeEvent(['subscription_id' => 'sub_1'])))->toBeFalse();
});

it('passes when local and live subscription prices match regardless of order', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $user = \Workbench\App\Models\User::create([
        'name' => 'Price Match',
        'email' => 'price-match@example.com',
        'password' => 'secret',
    ]);

    $subscription = Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_price_match',
        'stripe_status' => 'active',
    ]);

    $subscription->items()->create(['stripe_id' => 'si_1', 'stripe_product' => 'prod_1', 'stripe_price' => 'price_b']);
    $subscription->items()->create(['stripe_id' => 'si_2', 'stripe_product' => 'prod_2', 'stripe_price' => 'price_a']);

    $rule = new class extends SubscriptionPriceMismatchRule
    {
        protected function liveSubscription(Subscription $subscription): StripeSubscription
        {
            return StripeSubscription::constructFrom([
                'id' => $subscription->stripe_id,
                'items' => [
                    'object' => 'list',
                    'data' => [
                        ['id' => 'si_1', 'price' => ['id' => 'price_a']],
                        ['id' => 'si_2', 'price' => ['id' => 'price_b']],
                    ],
                ],
            ]);
        }
    };

    $result = $rule->diagnose($makeEvent(['subscription_id' => 'sub_price_match']));

    expect($result->isTriggered())->toBeFalse();
});

it('warns when local and live subscription prices differ', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $user = \Workbench\App\Models\User::create([
        'name' => 'Price Mismatch',
        'email' => 'price-mismatch@example.com',
        'password' => 'secret',
    ]);

    $subscription = Subscription::create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_price_mismatch',
        'stripe_status' => 'active',
    ]);

    $subscription->items()->create(['stripe_id' => 'si_1', 'stripe_product' => 'prod_1', 'stripe_price' => 'price_old']);

    $rule = new class extends SubscriptionPriceMismatchRule
    {
        protected function liveSubscription(Subscription $subscription): StripeSubscription
        {
            return StripeSubscription::constructFrom([
                'id' => $subscription->stripe_id,
                'items' => [
                    'object' => 'list',
                    'data' => [
                        ['id' => 'si_1', 'price' => ['id' => 'price_new']],
                    ],
                ],
            ]);
        }
    };

    $result = $rule->diagnose($makeEvent(['subscription_id' => 'sub_price_mismatch']));

    expect($result->isTriggered())->toBeTrue()
        ->and($result->code)->toBe('subscription_price_mismatch')
        ->and($result->context)->toBe([
            'subscription_id' => 'sub_price_mismatch',
            'local_prices_as_of_now' => ['price_old'],
            'stripe_prices_as_of_now' => ['price_new'],
        ]);
});

it('passes a price check when the event subscription id has no local match', function () use ($makeEvent) {
    config()->set('cashier-inspector.stripe_api_checks.enabled', true);

    $result = (new SubscriptionPriceMismatchRule)->diagnose($makeEvent(['subscription_id' => 'sub_unknown_price']));

    expect($result->isTriggered())->toBeFalse();
});
