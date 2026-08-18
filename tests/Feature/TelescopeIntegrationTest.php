<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\TelescopeIntegration;
use FelicianoPJ\CashierInspector\Support\WebhookCapture;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

beforeEach(function () {
    config()->set('cashier-inspector.enabled', true);
    config()->set('telescope.enabled', true);
    $this->app['env'] = 'local';
});

$capture = fn (string $id = 'evt_telescope') => WebhookCapture::fromPayload([
    'id' => $id,
    'type' => 'customer.subscription.updated',
    'livemode' => false,
]);

it('tags nothing on a request that is not a webhook', function () {
    $integration = app(TelescopeIntegration::class);

    expect($integration->tags())->toBe([]);
});

it('tags the entries recorded while a webhook is being processed', function () use ($capture) {
    app(WebhookCaptureContext::class)->start($capture());

    expect(app(TelescopeIntegration::class)->tags())
        ->toBe(['cashier-inspector', 'stripe-event:evt_telescope']);
});

it('registers its tag callback with Telescope', function () use ($capture) {
    Telescope::$tagUsing = [];

    app(TelescopeIntegration::class)->register();
    app(WebhookCaptureContext::class)->start($capture('evt_registered'));

    // Telescope applies these callbacks itself as it records each entry;
    // calling them is the closest a test can get without booting it.
    $tags = collect(Telescope::$tagUsing)
        ->flatMap(fn (Closure $callback) => $callback(IncomingEntry::make(['uri' => '/stripe/webhook'])))
        ->all();

    expect($tags)->toBe(['cashier-inspector', 'stripe-event:evt_registered']);
});

it('does not register anything when the integration is switched off', function () {
    Telescope::$tagUsing = [];
    config()->set('cashier-inspector.integrations.telescope', false);

    app(TelescopeIntegration::class)->register();

    expect(Telescope::$tagUsing)->toBe([]);
});

it('builds a link filtered to the event tag', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_link',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    expect(TelescopeIntegration::urlFor($event))
        ->toContain('/telescope/requests')
        ->toContain('tag=stripe-event%3Aevt_link');
});

it('honours a relocated Telescope path', function () {
    config()->set('telescope.path', 'admin/telescope');

    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_moved',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    expect(TelescopeIntegration::urlFor($event))->toContain('/admin/telescope/requests');
});

it('offers no link when Telescope is switched off', function () {
    config()->set('telescope.enabled', false);

    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_no_telescope',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    expect(TelescopeIntegration::urlFor($event))->toBeNull();
});

it('shows the link on the event page, and hides it when the integration is off', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_page_link',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $event->deliveries()->create([
        'status' => EventStatus::Handled,
        'severity' => Severity::Success,
        'received_at' => now(),
        'handled_at' => now(),
        'duration_ms' => 10,
    ]);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertSee('View in Telescope');

    config()->set('cashier-inspector.integrations.telescope', false);

    $this->get("cashier-inspector/events/{$event->stripe_event_id}")
        ->assertOk()
        ->assertDontSee('View in Telescope');
});
