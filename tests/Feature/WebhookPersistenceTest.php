<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Workbench\App\Models\User;

$subscriptionUpdatedPayload = fn (string $eventId = 'evt_sub_updated'): array => [
    'id' => $eventId,
    'type' => 'customer.subscription.updated',
    'api_version' => '2024-06-20',
    'livemode' => false,
    'data' => [
        'object' => [
            'id' => 'sub_123',
            'object' => 'subscription',
            'customer' => 'cus_123',
        ],
    ],
];

it('creates an event and a received delivery when WebhookReceived is dispatched', function () use ($subscriptionUpdatedPayload) {
    event(new WebhookReceived($subscriptionUpdatedPayload()));

    $event = InspectorEvent::where('stripe_event_id', 'evt_sub_updated')->sole();

    expect($event->stripe_event_type)->toBe('customer.subscription.updated')
        ->and($event->stripe_api_version)->toBe('2024-06-20')
        ->and($event->livemode)->toBeFalse()
        ->and($event->customer_id)->toBe('cus_123')
        ->and($event->subscription_id)->toBe('sub_123')
        ->and($event->payload)->toBeNull();

    $delivery = $event->deliveries()->sole();

    expect($delivery->status)->toBe(EventStatus::Received)
        ->and($delivery->received_at)->not->toBeNull();
});

it('resolves and stores the local billable model for the event customer', function () use ($subscriptionUpdatedPayload) {
    Cashier::useCustomerModel(User::class);

    $user = User::create([
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'secret',
    ]);
    $user->forceFill(['stripe_id' => 'cus_123'])->save();

    event(new WebhookReceived($subscriptionUpdatedPayload('evt_with_billable')));

    $event = InspectorEvent::where('stripe_event_id', 'evt_with_billable')->sole();

    expect($event->billable_type)->toBe(User::class)
        ->and($event->billable_id)->toBe($user->id);

    Cashier::useCustomerModel('App\Models\User');
});

it('leaves billable fields null when no local billable model matches', function () use ($subscriptionUpdatedPayload) {
    Cashier::useCustomerModel(User::class);

    event(new WebhookReceived($subscriptionUpdatedPayload('evt_without_billable')));

    $event = InspectorEvent::where('stripe_event_id', 'evt_without_billable')->sole();

    expect($event->billable_type)->toBeNull()
        ->and($event->billable_id)->toBeNull();

    Cashier::useCustomerModel('App\Models\User');
});

it('marks the delivery handled once WebhookHandled is dispatched', function () use ($subscriptionUpdatedPayload) {
    $payload = $subscriptionUpdatedPayload('evt_sub_handled');

    event(new WebhookReceived($payload));
    event(new WebhookHandled($payload));

    $delivery = InspectorEvent::where('stripe_event_id', 'evt_sub_handled')->sole()->deliveries()->sole();

    expect($delivery->status)->toBe(EventStatus::Handled)
        ->and($delivery->severity)->toBe(Severity::Success)
        ->and($delivery->handled_at)->not->toBeNull()
        ->and($delivery->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('creates a new delivery row without duplicating the event on redelivery', function () use ($subscriptionUpdatedPayload) {
    $payload = $subscriptionUpdatedPayload('evt_redelivered');

    event(new WebhookReceived($payload));
    event(new WebhookReceived($payload));

    expect(InspectorEvent::where('stripe_event_id', 'evt_redelivered')->count())->toBe(1)
        ->and(InspectorDelivery::whereHas('event', fn ($q) => $q->where('stripe_event_id', 'evt_redelivered'))->count())->toBe(2);
});

it('stores a redacted payload when store_payloads is enabled', function () use ($subscriptionUpdatedPayload) {
    config()->set('cashier-inspector.storage.store_payloads', true);

    $payload = $subscriptionUpdatedPayload('evt_with_payload');
    $payload['data']['object']['customer_email'] = 'jane@example.com';

    event(new WebhookReceived($payload));

    $event = InspectorEvent::where('stripe_event_id', 'evt_with_payload')->sole();

    expect($event->payload['data']['object']['customer_email'])->toBe('[redacted]')
        ->and($event->payload['data']['object']['customer'])->toBe('cus_123');
});

it('marks the delivery failed when the handler throws before WebhookHandled', function () {
    $payload = [
        'id' => 'evt_handler_throws',
        'type' => 'customer.updated',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => [
            'object' => [
                'id' => 'cus_456',
                'object' => 'customer',
            ],
        ],
    ];

    // No App\Models\User in the Testbench app, so Cashier::findBillable()
    // throws while resolving the default customer model.
    $this->postJson('cashier/webhook', $payload);

    $delivery = InspectorEvent::where('stripe_event_id', 'evt_handler_throws')->sole()->deliveries()->sole();

    expect($delivery->status)->toBe(EventStatus::Failed)
        ->and($delivery->severity)->toBe(Severity::Error)
        ->and($delivery->exception_class)->not->toBeNull()
        ->and($delivery->exception_trace)->toBeNull();
});

it('marks the delivery unmatched when Cashier has no handler for the event type', function () {
    $payload = [
        'id' => 'evt_unmatched',
        'type' => 'some.unhandled.event',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => ['object' => []],
    ];

    $this->postJson('cashier/webhook', $payload);

    $delivery = InspectorEvent::where('stripe_event_id', 'evt_unmatched')->sole()->deliveries()->sole();

    expect($delivery->status)->toBe(EventStatus::Unmatched)
        ->and($delivery->severity)->toBe(Severity::Info)
        ->and($delivery->duration_ms)->toBeGreaterThanOrEqual(0);
});
