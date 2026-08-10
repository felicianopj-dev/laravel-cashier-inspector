<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

$makeDashboardDelivery = function (array $eventOverrides = [], array $deliveryOverrides = []): InspectorDelivery {
    static $counter = 0;
    $counter++;

    $event = InspectorEvent::create(array_merge([
        'stripe_event_id' => "evt_dashboard_{$counter}",
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ], $eventOverrides));

    return $event->deliveries()->create(array_merge([
        'status' => EventStatus::Received,
        'received_at' => now(),
    ], $deliveryOverrides));
};

it('responds 404 when the dashboard is disabled', function () {
    config()->set('cashier-inspector.enabled', false);

    $this->get('cashier-inspector')->assertNotFound();
});

it('shows an empty state message when there are no problems', function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('No problems detected.');
});

it('shows only problem deliveries by default', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_success'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_error'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_unmatched'],
        ['status' => EventStatus::Unmatched, 'severity' => Severity::Info]
    );

    $response = $this->get('cashier-inspector')->assertOk();

    $response->assertSee('evt_error')->assertSee('evt_unmatched');
    $response->assertDontSee('evt_success');
});

it('treats a successful delivery with a warning diagnostic as a problem', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    // Cashier handled this one, so the delivery itself is a success. The
    // problem lives on the event: a duplicate delivery, a missing local
    // subscription, and the rest are all diagnosed after the fact.
    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_diagnosed'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $delivery->event->diagnostics()->create([
        'rule' => 'Manual',
        'code' => 'duplicate_delivery',
        'severity' => Severity::Warning,
        'title' => 'Duplicate delivery',
        'message' => 'Stripe delivered this event more than once.',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('evt_diagnosed');
});

it('does not treat an informational diagnostic as a problem', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_info_only'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $delivery->event->diagnostics()->create([
        'rule' => 'Manual',
        'code' => 'informational',
        'severity' => Severity::Info,
        'title' => 'Nothing to worry about',
        'message' => 'Purely informational.',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertDontSee('evt_info_only');
});

it('shows every delivery when the all filter is used', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_success_all'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $this->get('cashier-inspector?all=1')
        ->assertOk()
        ->assertSee('evt_success_all');
});

it('treats a delivery stuck as received for too long as a problem', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_stuck'],
        ['status' => EventStatus::Received, 'received_at' => now()->subMinutes(5)]
    );

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('evt_stuck');
});
