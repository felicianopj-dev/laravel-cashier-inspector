<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    $this->app['env'] = 'local';
});

$makeDelivery = function (array $eventOverrides, array $deliveryOverrides = []): InspectorDelivery {
    $event = InspectorEvent::create(array_merge([
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ], $eventOverrides));

    return $event->deliveries()->create(array_merge([
        'status' => EventStatus::Unmatched,
        'severity' => Severity::Info,
        'received_at' => now(),
    ], $deliveryOverrides));
};

/**
 * Order of the seeded event ids as they appear in the rendered table.
 *
 * @return array<int, string>
 */
$renderedOrder = function (string $url): array {
    $body = test()->get($url)->assertOk()->getContent();

    preg_match_all('/evt_sort_[a-z]+/', $body, $matches);

    return array_values(array_unique($matches[0]));
};

it('sorts by a column on the event ascending by default', function () use ($makeDelivery, $renderedOrder) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_charlie']);
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);
    $makeDelivery(['stripe_event_id' => 'evt_sort_bravo']);

    expect($renderedOrder('cashier-inspector?sort=event_id'))->toBe([
        'evt_sort_alpha',
        'evt_sort_bravo',
        'evt_sort_charlie',
    ]);
});

it('reverses that order when the direction is descending', function () use ($makeDelivery, $renderedOrder) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_charlie']);
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);
    $makeDelivery(['stripe_event_id' => 'evt_sort_bravo']);

    expect($renderedOrder('cashier-inspector?sort=event_id&direction=desc'))->toBe([
        'evt_sort_charlie',
        'evt_sort_bravo',
        'evt_sort_alpha',
    ]);
});

it('sorts by a column on the delivery itself', function () use ($makeDelivery, $renderedOrder) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_slow'], ['duration_ms' => 900]);
    $makeDelivery(['stripe_event_id' => 'evt_sort_quick'], ['duration_ms' => 5]);

    expect($renderedOrder('cashier-inspector?sort=duration'))->toBe([
        'evt_sort_quick',
        'evt_sort_slow',
    ]);
});

it('falls back to newest first for an unknown sort column', function () use ($makeDelivery, $renderedOrder) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_older'], ['received_at' => now()->subHour()]);
    $makeDelivery(['stripe_event_id' => 'evt_sort_newer'], ['received_at' => now()]);

    expect($renderedOrder('cashier-inspector?sort=payload'))->toBe([
        'evt_sort_newer',
        'evt_sort_older',
    ]);
});

it('links each column header to sort by it ascending', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);

    $this->get('cashier-inspector')
        ->assertOk()
        ->assertSee('sort=severity&amp;direction=asc', false)
        ->assertSee('sort=duration&amp;direction=asc', false);
});

it('flips the active column header link to descending', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);

    $response = $this->get('cashier-inspector?sort=status')->assertOk();

    // The active column offers the opposite direction, everything else
    // still offers ascending.
    $response->assertSee('sort=status&amp;direction=desc', false)
        ->assertSee('sort=severity&amp;direction=asc', false)
        ->assertSee('▲', false);
});

it('shows a descending arrow when sorted descending', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);

    $this->get('cashier-inspector?sort=status&direction=desc')
        ->assertOk()
        ->assertSee('▼', false)
        ->assertSee('sort=status&amp;direction=asc', false);
});

it('keeps the active filters in every column header link', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha', 'customer_id' => 'cus_sort']);

    $this->get('cashier-inspector?customer_id=cus_sort')
        ->assertOk()
        ->assertSee('customer_id=cus_sort&amp;sort=severity&amp;direction=asc', false);
});

it('keeps the ordering when the filter form is submitted', function () use ($makeDelivery) {
    $makeDelivery(['stripe_event_id' => 'evt_sort_alpha']);

    $this->get('cashier-inspector?sort=customer&direction=desc')
        ->assertOk()
        ->assertSee('<input type="hidden" name="sort" value="customer">', false)
        ->assertSee('<input type="hidden" name="direction" value="desc">', false);
});
