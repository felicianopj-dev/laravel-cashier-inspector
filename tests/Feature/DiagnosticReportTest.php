<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\DiagnosticReport;
use Illuminate\Support\Collection;

it('includes the event identity and environment header', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_report_1',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $report = DiagnosticReport::generate($event, null, new Collection);

    expect($report)->toContain('Laravel Cashier Inspector Report')
        ->toContain('Event: customer.subscription.updated')
        ->toContain('Stripe Event: evt_report_1')
        ->toContain('Mode: test')
        ->toContain('Laravel: '.app()->version())
        ->toContain('PHP: '.PHP_VERSION)
        ->toContain('No diagnostic rules triggered for this event.');
});

it('includes the latest delivery status', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_report_2',
        'stripe_event_type' => 'invoice.payment_failed',
        'livemode' => true,
    ]);

    $delivery = $event->deliveries()->create([
        'status' => EventStatus::Failed,
        'severity' => Severity::Error,
        'received_at' => now(),
    ]);

    $report = DiagnosticReport::generate($event, $delivery, new Collection);

    expect($report)->toContain('Mode: live')
        ->toContain('Status: failed');
});

it('lists triggered diagnostics with context and numbered suggested checks', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_report_3',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $diagnostics = collect([
        $event->diagnostics()->create([
            'rule' => 'FelicianoPJ\\CashierInspector\\Diagnostics\\Rules\\ExampleRule',
            'code' => 'example_code',
            'severity' => Severity::Warning,
            'title' => 'Example finding',
            'message' => 'Something worth checking.',
            'context' => [
                'payload_status' => 'active',
                'suggested_checks' => ['Confirm that the webhook handler completed.'],
            ],
            'created_at' => now(),
        ]),
    ]);

    $report = DiagnosticReport::generate($event, null, $diagnostics);

    expect($report)->toContain('[warning] Example finding')
        ->toContain('Something worth checking.')
        ->toContain('Payload status: active')
        ->toContain("Suggested checks:\n1. Confirm that the webhook handler completed.");
});

it('never leaks the raw event payload into the report', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_report_4',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
        'payload' => ['data' => ['object' => ['customer_email' => 'jane@example.com']]],
    ]);

    $report = DiagnosticReport::generate($event, null, new Collection);

    expect($report)->not->toContain('jane@example.com');
});
