<?php

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Http\Middleware\InstrumentCashierWebhook;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\WebhookCapture;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

/**
 * Attaches the middleware the same way the service provider does, so the
 * tests exercise the real route rather than a stand-in.
 */
$instrument = function (): int {
    $attached = 0;

    foreach (Route::getRoutes() as $route) {
        try {
            $controller = $route->getController();
        } catch (Throwable) {
            continue;
        }

        if ($controller instanceof WebhookController) {
            $route->middleware(InstrumentCashierWebhook::class);
            $attached++;
        }
    }

    return $attached;
};

$brokenPayload = [
    'id' => 'evt_instrumented',
    'type' => 'customer.subscription.updated',
    'api_version' => '2024-06-20',
    'livemode' => false,
    'data' => ['object' => [
        'id' => 'sub_instrumented',
        'object' => 'subscription',
        'customer' => 'cus_instrumented',
        'items' => 'not-a-list',
    ]],
];

it('is not attached to any route by default', function () {
    $matched = 0;

    foreach (Route::getRoutes() as $route) {
        try {
            $controller = $route->getController();
        } catch (Throwable) {
            continue;
        }

        if ($controller instanceof WebhookController) {
            $matched++;
            expect($route->gatherMiddleware())->not->toContain(InstrumentCashierWebhook::class);
        }
    }

    expect($matched)->toBeGreaterThan(0);
});

it('finds Cashier\'s route by its controller, whatever the path', function () use ($instrument) {
    config()->set('cashier.path', 'billing/hooks');

    expect($instrument())->toBeGreaterThan(0);
});

it('records an exception the application never reports', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_unreported',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $delivery = $event->deliveries()->create([
        'status' => EventStatus::Received,
        'received_at' => now(),
    ]);

    $capture = WebhookCapture::fromPayload([
        'id' => 'evt_unreported',
        'type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);
    $capture->deliveryId = $delivery->id;
    $capture->eventId = $event->id;

    app(WebhookCaptureContext::class)->start($capture);

    $middleware = app(InstrumentCashierWebhook::class);

    // Nothing here goes near the exception handler, which is the point: the
    // reporting hook would never see this.
    expect(fn () => $middleware->handle(
        Request::create('/stripe/webhook', 'POST'),
        fn () => throw new RuntimeException('handler exploded')
    ))->toThrow(RuntimeException::class, 'handler exploded');

    $delivery->refresh();

    expect($delivery->status)->toBe(EventStatus::Failed)
        ->and($delivery->severity)->toBe(Severity::Error)
        ->and($delivery->exception_class)->toBe(RuntimeException::class)
        ->and($delivery->exception_message)->toBe('handler exploded');
});

it('runs the diagnostics the reporting hook would have run', function () {
    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_diag',
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);

    $delivery = $event->deliveries()->create([
        'status' => EventStatus::Received,
        'received_at' => now(),
    ]);

    $capture = WebhookCapture::fromPayload([
        'id' => 'evt_diag',
        'type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);
    $capture->deliveryId = $delivery->id;
    $capture->eventId = $event->id;

    app(WebhookCaptureContext::class)->start($capture);

    try {
        app(InstrumentCashierWebhook::class)->handle(
            Request::create('/stripe/webhook', 'POST'),
            fn () => throw new RuntimeException('boom')
        );
    } catch (RuntimeException) {
        // expected
    }

    expect($event->diagnostics()->count())->toBeGreaterThan(0);
});

it('bounds the first phase by the route rather than by the listener', function () use ($instrument, $brokenPayload) {
    $instrument();

    $this->postJson('cashier/webhook', [
        'id' => 'evt_signature_phase',
        'type' => 'some.unhandled.event',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => ['object' => []],
    ]);

    $steps = InspectorEvent::where('stripe_event_id', 'evt_signature_phase')
        ->sole()->deliveries()->sole()->steps()->get();

    $received = $steps->firstWhere('step', Step::RequestReceived);

    expect($received->message)->toBe("Reached Cashier's webhook route.")
        ->and($steps->where('step', Step::RequestReceived))->toHaveCount(1);
});

it('lets the exception through to Cashier untouched', function () use ($instrument, $brokenPayload) {
    $instrument();

    $this->postJson('cashier/webhook', $brokenPayload);

    $delivery = InspectorEvent::where('stripe_event_id', 'evt_instrumented')
        ->sole()->deliveries()->sole();

    expect($delivery->status)->toBe(EventStatus::Failed)
        ->and($delivery->exception_class)->not->toBeNull()
        ->and($delivery->steps()->get()->firstWhere('step', Step::CashierHandler)->status)
        ->toBe(StepStatus::Failed);
});
