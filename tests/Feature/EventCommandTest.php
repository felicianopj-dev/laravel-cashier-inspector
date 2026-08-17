<?php

use FelicianoPJ\CashierInspector\Diagnostics\Rules\SlowProcessingRule;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Facades\Artisan;

/**
 * Output is captured rather than asserted through expectsOutputToContain():
 * the report is written in a single call, and that assertion is backed by
 * Mockery expectations on doWrite, which match one call against only the
 * first matching expectation. Several substrings from one write would all
 * but one be reported as missing.
 */
$run = function (string $id): array {
    $status = Artisan::call('cashier-inspector:event', ['event' => $id]);

    return [$status, Artisan::output()];
};

$makeEvent = function (string $id = 'evt_cli'): InspectorEvent {
    return InspectorEvent::create([
        'stripe_event_id' => $id,
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
        'customer_id' => 'cus_cli',
        'subscription_id' => 'sub_cli',
    ]);
};

it('fails when no event was captured for the given id', function () use ($run) {
    [$status, $output] = $run('evt_missing');

    expect($status)->toBe(1)
        ->and($output)->toContain('No captured event with the Stripe event id [evt_missing].');
});

it('prints the event summary and its findings', function () use ($makeEvent, $run) {
    $event = $makeEvent();

    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'handled_at' => now(),
        'duration_ms' => 42,
    ]);

    InspectorDiagnostic::create([
        'event_id' => $event->id,
        'rule' => SlowProcessingRule::class,
        'code' => 'slow_processing',
        'severity' => Severity::Warning,
        'title' => 'Processing was slow',
        'message' => 'This delivery took longer than the configured threshold.',
        'context' => ['suggested_checks' => ['Look for slow queries in the handler.']],
        'created_at' => now(),
    ]);

    [$status, $output] = $run('evt_cli');

    expect($status)->toBe(0)
        ->and($output)->toContain('Laravel Cashier Inspector Report')
        ->and($output)->toContain('customer.subscription.updated')
        ->and($output)->toContain('evt_cli')
        ->and($output)->toContain('Processing was slow')
        ->and($output)->toContain('Look for slow queries in the handler.')
        ->and($output)->toContain('42 ms');
});

it('lists every delivery attempt, not just the latest', function () use ($makeEvent, $run) {
    $event = $makeEvent('evt_redelivered_cli');

    $event->deliveries()->create([
        'status' => EventStatus::Failed,
        'severity' => Severity::Error,
        'received_at' => now()->subMinute(),
        'duration_ms' => 10,
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'handled_at' => now(),
        'duration_ms' => 20,
    ]);

    [$status, $output] = $run('evt_redelivered_cli');

    expect($status)->toBe(0)
        ->and($output)->toContain('10 ms')
        ->and($output)->toContain('20 ms');
});

it('reports an event that has no findings without failing', function () use ($makeEvent, $run) {
    $makeEvent('evt_clean_cli');

    [$status, $output] = $run('evt_clean_cli');

    expect($status)->toBe(0)
        ->and($output)->toContain('No diagnostic rules triggered for this event.');
});
