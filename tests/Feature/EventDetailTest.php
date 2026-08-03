<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';
});

it('responds 404 when the dashboard is disabled', function () {
    config()->set('cashier-inspector.enabled', false);

    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_disabled',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")->assertNotFound();
});

it('responds 404 for an unknown event id', function () {
    $this->get('cashier-inspector/events/evt_does_not_exist')->assertNotFound();
});

it('shows the summary and processing timeline for the latest delivery', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_1',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
        'customer_id' => 'cus_1',
        'subscription_id' => 'sub_1',
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now()->subSeconds(5),
        'handled_at' => now(),
        'duration_ms' => 120,
    ]);

    $response = $this->get("cashier-inspector/events/{$event->stripe_event_id}")->assertOk();

    $response->assertSee('customer.subscription.updated')
        ->assertSee('evt_detail_1')
        ->assertSee('cus_1')
        ->assertSee('sub_1')
        ->assertSee('120 ms')
        ->assertSee('Event received')
        ->assertSee('Event handled')
        ->assertSee('Copy diagnostic report')
        ->assertSee('Stripe Event: evt_detail_1');
});

it('lists every delivery attempt for a redelivered event', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_redelivered',
        'stripe_event_type' => 'invoice.payment_failed',
        'livemode' => false,
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Failed,
        'severity' => Severity::Error,
        'received_at' => now()->subMinutes(5),
        'duration_ms' => 30,
        'exception_class' => 'RuntimeException',
        'exception_message' => 'First attempt failed.',
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'handled_at' => now(),
        'duration_ms' => 10,
    ]);

    $response = $this->get("cashier-inspector/events/{$event->stripe_event_id}")->assertOk();

    $response->assertSee('Delivery attempts (2)')
        ->assertSee('was delivered 2 times')
        ->assertSee('RuntimeException')
        ->assertSee('First attempt failed.');
});

it('shows a placeholder when payload storage is disabled', function () {
    config()->set('cashier-inspector.storage.store_payloads', false);

    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_no_payload',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
        'payload' => null,
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertSee('storage.store_payloads is off');
});

it('shows the redacted payload when it was stored', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_with_payload',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
        'payload' => ['data' => ['object' => ['customer_email' => '[redacted]']]],
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertSee('[redacted]')
        ->assertSee('Show payload (redacted)');
});

it('shows a placeholder when no diagnostics have triggered', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_no_diagnostics',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertSee('No diagnostic rules are registered yet')
        ->assertSee('No diagnostic rules have triggered for this event.')
        ->assertSee('No suggested checks yet');
});

it('shows triggered diagnostics, their severity, and suggested checks', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_diagnostics',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
    ]);

    $event->diagnostics()->create([
        'rule' => 'FelicianoPJ\\CashierInspector\\Diagnostics\\Rules\\ExampleRule',
        'code' => 'example_code',
        'severity' => Severity::Warning,
        'title' => 'Example finding',
        'message' => 'Something worth checking.',
        'context' => ['suggested_checks' => ['Check Stripe dashboard for details.']],
        'created_at' => now(),
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertSee('Example finding')
        ->assertSee('Something worth checking.')
        ->assertSee('example_code')
        ->assertSee('ExampleRule')
        ->assertSee('Check Stripe dashboard for details.');
});

it('links the dashboard event id to its detail page', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_detail_linked',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Failed,
        'severity' => Severity::Error,
        'received_at' => now(),
    ]);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee(route('cashier-inspector.events.show', $event), false);
});
