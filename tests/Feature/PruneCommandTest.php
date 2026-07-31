<?php

use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

$makeEventAt = function (string $id, \Illuminate\Support\Carbon $createdAt): InspectorEvent {
    $event = InspectorEvent::create([
        'stripe_event_id' => $id,
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $event;
};

it('deletes events older than the configured retention period', function () use ($makeEventAt) {
    config()->set('cashier-inspector.storage.retention_days', 7);

    $old = $makeEventAt('evt_old', now()->subDays(10));
    $recent = $makeEventAt('evt_recent', now()->subDays(1));

    $this->artisan('cashier-inspector:prune')->assertSuccessful();

    expect(InspectorEvent::find($old->id))->toBeNull()
        ->and(InspectorEvent::find($recent->id))->not->toBeNull();
});

it('cascades to deliveries and diagnostics when an event is pruned', function () use ($makeEventAt) {
    config()->set('cashier-inspector.storage.retention_days', 7);

    $old = $makeEventAt('evt_old_with_children', now()->subDays(10));

    $old->deliveries()->create([
        'status' => \FelicianoPJ\CashierInspector\Enums\EventStatus::Failed,
        'severity' => \FelicianoPJ\CashierInspector\Enums\Severity::Error,
        'received_at' => now()->subDays(10),
    ]);

    $old->diagnostics()->create([
        'rule' => 'SomeRule',
        'code' => 'some_code',
        'severity' => \FelicianoPJ\CashierInspector\Enums\Severity::Error,
        'title' => 'Title',
        'message' => 'Message',
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('cashier-inspector:prune');

    expect(\FelicianoPJ\CashierInspector\Models\InspectorDelivery::where('event_id', $old->id)->count())->toBe(0)
        ->and(InspectorDiagnostic::where('event_id', $old->id)->count())->toBe(0);
});

it('accepts a --days override', function () use ($makeEventAt) {
    config()->set('cashier-inspector.storage.retention_days', 30);

    $event = $makeEventAt('evt_override', now()->subDays(5));

    $this->artisan('cashier-inspector:prune', ['--days' => 1])->assertSuccessful();

    expect(InspectorEvent::find($event->id))->toBeNull();
});

it('fails without deleting anything when retention is not a positive number', function () use ($makeEventAt) {
    $event = $makeEventAt('evt_zero_retention', now()->subDays(100));

    $this->artisan('cashier-inspector:prune', ['--days' => 0])->assertFailed();

    expect(InspectorEvent::find($event->id))->not->toBeNull();
});
