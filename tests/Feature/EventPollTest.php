<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

$makePollDelivery = function (array $eventOverrides = [], array $deliveryOverrides = []): InspectorDelivery {
    static $counter = 0;
    $counter++;

    $event = InspectorEvent::create(array_merge([
        'stripe_event_id' => "evt_poll_{$counter}",
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ], $eventOverrides));

    return $event->deliveries()->create(array_merge([
        'status' => EventStatus::Received,
        'received_at' => now(),
    ], $deliveryOverrides));
};

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';
});

it('responds 404 when the dashboard is disabled', function () {
    config()->set('cashier-inspector.enabled', false);

    $this->getJson('cashier-inspector/api/events')->assertNotFound();
});

it('returns an empty payload with latest_id 0 when there is nothing to report', function () {
    $response = $this->getJson('cashier-inspector/api/events')->assertOk();

    $response->assertJson([
        'events' => [],
        'latest_id' => 0,
    ])->assertJsonStructure(['server_time']);
});

it('only returns deliveries newer than the given after id', function () use ($makePollDelivery) {
    $old = $makePollDelivery(
        ['stripe_event_id' => 'evt_old'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $new = $makePollDelivery(
        ['stripe_event_id' => 'evt_new'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $response = $this->getJson("cashier-inspector/api/events?after={$old->id}")->assertOk();

    $ids = collect($response->json('events'))->pluck('stripe_event_id');

    expect($ids)->toContain('evt_new')->not->toContain('evt_old')
        ->and($response->json('latest_id'))->toBe($new->id);
});

it('excludes successful deliveries from the default problems-only poll', function () use ($makePollDelivery) {
    $makePollDelivery(
        ['stripe_event_id' => 'evt_success'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $response = $this->getJson('cashier-inspector/api/events')->assertOk();

    expect($response->json('events'))->toBeEmpty()
        ->and($response->json('latest_id'))->toBe(0);
});

it('includes successful deliveries when polling with the all filter', function () use ($makePollDelivery) {
    $delivery = $makePollDelivery(
        ['stripe_event_id' => 'evt_success_all'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $response = $this->getJson('cashier-inspector/api/events?all=1')->assertOk();

    $ids = collect($response->json('events'))->pluck('stripe_event_id');

    expect($ids)->toContain('evt_success_all')
        ->and($response->json('latest_id'))->toBe($delivery->id);
});

it('shapes each event payload with the dashboard columns', function () use ($makePollDelivery) {
    $delivery = $makePollDelivery(
        ['stripe_event_id' => 'evt_shape', 'customer_id' => 'cus_1', 'subscription_id' => 'sub_1'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error, 'duration_ms' => 42]
    );

    $response = $this->getJson('cashier-inspector/api/events')->assertOk();

    expect($response->json('events.0'))->toMatchArray([
        'id' => $delivery->id,
        'status' => 'failed',
        'severity' => 'error',
        'stripe_event_id' => 'evt_shape',
        'stripe_event_type' => 'customer.subscription.updated',
        'customer_id' => 'cus_1',
        'subscription_id' => 'sub_1',
        'livemode' => false,
        'duration_ms' => 42,
    ]);
});
