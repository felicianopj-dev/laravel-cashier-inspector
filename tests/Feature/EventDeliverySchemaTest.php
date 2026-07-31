<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Facades\Schema;

it('creates the events and deliveries tables', function () {
    expect(Schema::hasTable('cashier_inspector_events'))->toBeTrue()
        ->and(Schema::hasTable('cashier_inspector_deliveries'))->toBeTrue();
});

it('enforces a unique stripe_event_id on events', function () {
    InspectorEvent::create([
        'stripe_event_id' => 'evt_123',
        'stripe_event_type' => 'invoice.paid',
        'livemode' => false,
    ]);

    expect(fn () => InspectorEvent::create([
        'stripe_event_id' => 'evt_123',
        'stripe_event_type' => 'invoice.paid',
        'livemode' => false,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('links a delivery to its event and casts status and severity', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_456',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $delivery = InspectorDelivery::create([
        'event_id' => $event->id,
        'status' => EventStatus::Failed,
        'severity' => Severity::Error,
        'received_at' => now(),
        'exception_class' => \RuntimeException::class,
        'exception_message' => 'Something went wrong.',
    ]);

    expect($delivery->status)->toBe(EventStatus::Failed)
        ->and($delivery->severity)->toBe(Severity::Error)
        ->and($delivery->event->is($event))->toBeTrue()
        ->and($event->deliveries->first()->is($delivery))->toBeTrue();
});

it('deletes deliveries when their event is deleted', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_789',
        'stripe_event_type' => 'invoice.payment_failed',
        'livemode' => false,
    ]);

    InspectorDelivery::create([
        'event_id' => $event->id,
        'status' => EventStatus::Received,
    ]);

    $event->delete();

    expect(InspectorDelivery::count())->toBe(0);
});
