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
