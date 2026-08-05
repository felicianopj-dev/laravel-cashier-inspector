<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\DeliveryFilters;
use Laravel\Cashier\Cashier;
use Workbench\App\Models\User;

$makeDelivery = function (array $eventOverrides, array $deliveryOverrides = []) {
    static $counter = 0;
    $counter++;

    $event = InspectorEvent::create(array_merge([
        'stripe_event_id' => "evt_filter_{$counter}",
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ], $eventOverrides));

    return $event->deliveries()->create(array_merge([
        'status' => EventStatus::Received,
        'received_at' => now(),
    ], $deliveryOverrides));
};

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';
});

afterEach(function () {
    Cashier::useCustomerModel('App\Models\User');
});

it('finds an event by a partial stripe event id via search', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_abc123'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);
    $makeDelivery(['stripe_event_id' => 'evt_other'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $this->get('cashier-inspector?search=abc123')
        ->assertOk()
        ->assertSee('evt_abc123')
        ->assertDontSee('evt_other');
});

it('finds an event by customer id via search', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_by_customer', 'customer_id' => 'cus_findme'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_unrelated', 'customer_id' => 'cus_other'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&search=findme')
        ->assertOk()
        ->assertSee('evt_by_customer')
        ->assertDontSee('evt_unrelated');
});

it('finds an event by local billable model id via search', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_by_billable', 'billable_type' => 'Workbench\\App\\Models\\User', 'billable_id' => 42],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_other_billable', 'billable_type' => 'Workbench\\App\\Models\\User', 'billable_id' => 7],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&search=42')
        ->assertOk()
        ->assertSee('evt_by_billable')
        ->assertDontSee('evt_other_billable');
});

it('filters by severity', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_warn'], ['status' => EventStatus::Failed, 'severity' => Severity::Warning]);
    $makeDelivery(['stripe_event_id' => 'evt_err'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $this->get('cashier-inspector?all=1&severity=warning')
        ->assertOk()
        ->assertSee('evt_warn')
        ->assertDontSee('evt_err');
});

it('filters by status', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_unmatched'], ['status' => EventStatus::Unmatched, 'severity' => Severity::Info]);
    $makeDelivery(['stripe_event_id' => 'evt_failed'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $this->get('cashier-inspector?all=1&status=unmatched')
        ->assertOk()
        ->assertSee('evt_unmatched')
        ->assertDontSee('evt_failed');
});

it('filters by event type', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_invoice', 'stripe_event_type' => 'invoice.payment_failed'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_sub', 'stripe_event_type' => 'customer.subscription.updated'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&event_type=invoice.payment_failed')
        ->assertOk()
        ->assertSee('evt_invoice')
        ->assertDontSee('evt_sub');
});

it('filters by test/live mode', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_live', 'livemode' => true],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_test', 'livemode' => false],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&mode=live')
        ->assertOk()
        ->assertSee('evt_live')
        ->assertDontSee('evt_test');
});

it('filters by customer id and subscription id', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_match', 'customer_id' => 'cus_1', 'subscription_id' => 'sub_1'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_no_match', 'customer_id' => 'cus_2', 'subscription_id' => 'sub_2'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&customer_id=cus_1&subscription_id=sub_1')
        ->assertOk()
        ->assertSee('evt_match')
        ->assertDontSee('evt_no_match');
});

it('filters by a received date range', function () use ($makeDelivery) {
    $makeDelivery(
        ['stripe_event_id' => 'evt_old'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error, 'received_at' => now()->subDays(10)]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_recent'],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error, 'received_at' => now()]
    );

    $this->get('cashier-inspector?all=1&from='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertSee('evt_recent')
        ->assertDontSee('evt_old');
});

it('applies the same filters to the polling endpoint', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_poll_warn'], ['status' => EventStatus::Failed, 'severity' => Severity::Warning]);
    $makeDelivery(['stripe_event_id' => 'evt_poll_err'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $response = $this->getJson('cashier-inspector/api/events?all=1&severity=warning')->assertOk();

    $ids = collect($response->json('events'))->pluck('stripe_event_id');

    expect($ids)->toContain('evt_poll_warn')->not->toContain('evt_poll_err');
});

it('builds query params from only the active filters', function () {
    $filters = new DeliveryFilters(problemsOnly: false, severity: 'warning', customerId: 'cus_1');

    expect($filters->queryParams())->toBe([
        'all' => '1',
        'severity' => 'warning',
        'customer_id' => 'cus_1',
    ]);
});

it('ignores an unparseable date filter instead of erroring', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_bad_date'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $this->get('cashier-inspector?all=1&from=not-a-date')
        ->assertOk()
        ->assertSee('evt_bad_date');
});

it('finds an event by local billable email via search', function () use ($makeDelivery) {
    Cashier::useCustomerModel(User::class);

    $jane = User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

    $makeDelivery(
        ['stripe_event_id' => 'evt_jane', 'billable_type' => User::class, 'billable_id' => $jane->id],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_bob', 'billable_type' => User::class, 'billable_id' => $bob->id],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&search=jane@example.com')
        ->assertOk()
        ->assertSee('evt_jane')
        ->assertDontSee('evt_bob');
});

it('finds an event by a partial local billable email via search', function () use ($makeDelivery) {
    Cashier::useCustomerModel(User::class);

    $jane = User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@other.test', 'password' => 'secret']);

    $makeDelivery(
        ['stripe_event_id' => 'evt_partial_jane', 'billable_type' => User::class, 'billable_id' => $jane->id],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );
    $makeDelivery(
        ['stripe_event_id' => 'evt_partial_bob', 'billable_type' => User::class, 'billable_id' => $bob->id],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&search=example.com')
        ->assertOk()
        ->assertSee('evt_partial_jane')
        ->assertDontSee('evt_partial_bob');
});

it('does not match an event whose billable_type is not the configured customer model', function () use ($makeDelivery) {
    Cashier::useCustomerModel(User::class);

    $jane = User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret']);

    $makeDelivery(
        ['stripe_event_id' => 'evt_other_type', 'billable_type' => 'App\\Models\\Team', 'billable_id' => $jane->id],
        ['status' => EventStatus::Failed, 'severity' => Severity::Error]
    );

    $this->get('cashier-inspector?all=1&search=jane@example.com')
        ->assertOk()
        ->assertDontSee('evt_other_type');
});

it('still searches identifiers when the customer model class does not exist', function () use ($makeDelivery) {
    Cashier::useCustomerModel('App\Models\User');

    $makeDelivery(['stripe_event_id' => 'evt_no_model'], ['status' => EventStatus::Failed, 'severity' => Severity::Error]);

    $this->get('cashier-inspector?all=1&search=evt_no_model')
        ->assertOk()
        ->assertSee('evt_no_model');
});
