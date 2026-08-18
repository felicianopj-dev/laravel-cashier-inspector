<?php

use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Models\InspectorStep;
use Laravel\Cashier\Cashier;
use Workbench\App\Models\User;

beforeEach(function () {
    // Cashier's handler resolves the billable model through the configured
    // customer model, which defaults to a class the workbench doesn't have.
    Cashier::useCustomerModel(User::class);

    User::create([
        'name' => 'Timeline',
        'email' => 'timeline@example.com',
        'password' => 'secret',
    ])->forceFill(['stripe_id' => 'cus_timeline'])->save();
});

afterEach(function () {
    Cashier::useCustomerModel('App\Models\User');
});

$handledPayload = fn (string $eventId = 'evt_timeline'): array => [
    'id' => $eventId,
    'type' => 'customer.subscription.updated',
    'api_version' => '2024-06-20',
    'livemode' => false,
    'data' => [
        'object' => [
            'id' => 'sub_timeline',
            'object' => 'subscription',
            'customer' => 'cus_timeline',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_timeline',
                        'object' => 'subscription_item',
                        'quantity' => 1,
                        'price' => [
                            'id' => 'price_timeline',
                            'object' => 'price',
                            'product' => 'prod_timeline',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$stepsFor = fn (string $eventId) => InspectorEvent::where('stripe_event_id', $eventId)
    ->sole()
    ->deliveries()
    ->sole()
    ->steps()
    ->get();

it('records every observable phase of a handled webhook, in order', function () use ($handledPayload, $stepsFor) {
    $this->postJson('cashier/webhook', $handledPayload());

    $steps = $stepsFor('evt_timeline');

    expect($steps->pluck('step')->all())->toBe([
        Step::RequestReceived,
        Step::EventCaptured,
        Step::CashierHandler,
        Step::Diagnostics,
        Step::Response,
    ]);

    expect($steps->pluck('status')->unique()->values()->all())->toBe([StepStatus::Ok]);
});

it('times each phase and never leaves one unfinished', function () use ($handledPayload, $stepsFor) {
    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_timed'));

    foreach ($stepsFor('evt_timeline_timed') as $step) {
        expect($step->started_at)->not->toBeNull()
            ->and($step->finished_at)->not->toBeNull()
            ->and($step->duration_ms)->toBeGreaterThanOrEqual(0);
    }
});

it('names the resolved billable model on the capture phase', function () use ($handledPayload, $stepsFor) {
    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_billable'));

    $captured = $stepsFor('evt_timeline_billable')->firstWhere('step', Step::EventCaptured);

    expect($captured->message)->toContain(User::class)
        ->and($captured->message)->toStartWith('Billable model resolved:');
});

it('summarises the diagnostics run', function () use ($handledPayload, $stepsFor) {
    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_diagnostics'));

    $diagnostics = $stepsFor('evt_timeline_diagnostics')->firstWhere('step', Step::Diagnostics);

    expect($diagnostics->message)->toContain('rules ran')
        ->and($diagnostics->message)->toContain('recorded.');
});

it('records the handler phase as skipped when Cashier has no handler for the event type', function () use ($stepsFor) {
    $this->postJson('cashier/webhook', [
        'id' => 'evt_timeline_unmatched',
        'type' => 'some.unhandled.event',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => ['object' => []],
    ]);

    $handler = $stepsFor('evt_timeline_unmatched')->firstWhere('step', Step::CashierHandler);

    expect($handler->status)->toBe(StepStatus::Skipped)
        ->and($handler->message)->toBe('Cashier has no handler for this event type.');
});

it('records the handler phase as failed when the handler throws', function () use ($stepsFor) {
    $this->postJson('cashier/webhook', [
        'id' => 'evt_timeline_failed',
        'type' => 'customer.subscription.updated',
        'api_version' => '2024-06-20',
        'livemode' => false,
        // A real billable model, so Cashier gets past its "if ($user)" guard
        // and into the item loop, where the missing price throws.
        'data' => ['object' => [
            'id' => 'sub_broken',
            'object' => 'subscription',
            'customer' => 'cus_timeline',
            'status' => 'active',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_broken', 'object' => 'subscription_item']]],
        ]],
    ]);

    $steps = $stepsFor('evt_timeline_failed');
    $handler = $steps->firstWhere('step', Step::CashierHandler);

    expect($handler->status)->toBe(StepStatus::Failed)
        ->and($handler->message)->not->toBeNull()
        ->and($steps->firstWhere('step', Step::Response)->status)->toBe(StepStatus::Failed);
});

it('records nothing when step recording is turned off', function () use ($handledPayload) {
    config()->set('cashier-inspector.steps.enabled', false);

    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_disabled'));

    expect(InspectorStep::count())->toBe(0);
});

it('does not attach one request\'s phases to the next request\'s delivery', function () use ($handledPayload) {
    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_first'));
    $this->postJson('cashier/webhook', $handledPayload('evt_timeline_second'));

    $deliveries = InspectorEvent::where('stripe_event_id', 'evt_timeline_first')->sole()->deliveries;

    expect(InspectorStep::where('delivery_id', $deliveries->sole()->id)->count())->toBe(5);
});
