<?php

use FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule;
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

it('shows the worst finding on the event as the row severity', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    // Cashier handled it without complaint, so the row itself is a success.
    // The finding is the whole reason the event is in the default view, so
    // the list has to say so rather than reading as healthy.
    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_rollup'],
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
        ->assertSee('badge severity-warning', false)
        ->assertDontSee('badge severity-success', false);
});

it('does not raise the row severity for an environment finding', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_rollup_environment'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $delivery->event->diagnostics()->create([
        'rule' => MissingWebhookSecretRule::class,
        'code' => 'webhook_secret_missing',
        'severity' => Severity::Warning,
        'title' => 'Stripe webhook secret is not configured',
        'message' => 'Nothing verifies that incoming requests came from Stripe.',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->get('cashier-inspector?all=1')
        ->assertOk()
        ->assertSee('badge severity-success', false)
        ->assertDontSee('badge severity-warning', false);
});

it('does not treat an environment diagnostic as a per-event problem', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    // A missing webhook secret is diagnosed on every event, so counting it
    // as a per-event problem would flag the whole dashboard.
    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_environment_only'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    $delivery->event->diagnostics()->create([
        'rule' => MissingWebhookSecretRule::class,
        'code' => 'webhook_secret_missing',
        'severity' => Severity::Warning,
        'title' => 'Stripe webhook secret is not configured',
        'message' => 'Nothing verifies that incoming requests came from Stripe.',
        'context' => [],
        'created_at' => now(),
    ]);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertDontSee('evt_environment_only');
});

it('still shows an event that has both an environment and an event diagnostic', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $delivery = $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_environment_plus_real'],
        ['status' => EventStatus::Handled, 'severity' => Severity::Success]
    );

    foreach ([
        [MissingWebhookSecretRule::class, 'webhook_secret_missing'],
        ['Manual', 'duplicate_delivery'],
    ] as [$rule, $code]) {
        $delivery->event->diagnostics()->create([
            'rule' => $rule,
            'code' => $code,
            'severity' => Severity::Warning,
            'title' => $code,
            'message' => $code,
            'context' => [],
            'created_at' => now(),
        ]);
    }

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('evt_environment_plus_real');
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

it('paginates with plain links rather than the Tailwind paginator view', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    foreach (range(1, 26) as $i) {
        $makeDashboardDelivery(
            ['stripe_event_id' => "evt_page_{$i}"],
            ['status' => EventStatus::Unmatched, 'severity' => Severity::Info]
        );
    }

    $response = $this->get('cashier-inspector')->assertOk();

    $response->assertSee('Showing 1-25 of 26')
        ->assertSee('Page 1 of 2')
        ->assertSee('Next');

    // The Tailwind paginator view ships inline SVG arrows sized only by
    // utility classes this dashboard does not load, so they render huge.
    $response->assertDontSee('<svg', false);
});

it('does not paginate a single page of results', function () use ($makeDashboardDelivery) {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';

    $makeDashboardDelivery(
        ['stripe_event_id' => 'evt_single_page'],
        ['status' => EventStatus::Unmatched, 'severity' => Severity::Info]
    );

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertDontSee('Showing 1-1 of 1');
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
