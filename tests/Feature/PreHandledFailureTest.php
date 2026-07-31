<?php

use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Facades\Route;

it('records an exception thrown while handling a Cashier webhook request', function () {
    // No "type" key: Cashier's controller indexes $payload['type'] before
    // dispatching WebhookReceived, so this throws before Cashier Inspector's
    // own webhook listeners ever see the request.
    $this->postJson('cashier/webhook', ['id' => 'evt_no_type']);

    $failure = app(WebhookCaptureContext::class)->failure();

    expect($failure)->not->toBeNull()
        ->and($failure->exceptionMessage)->toContain('type');
});

it('records the terminating response status for a Cashier webhook request', function () {
    $this->postJson('cashier/webhook', ['id' => 'evt_no_type']);

    expect(app(WebhookCaptureContext::class)->terminatedStatus())->toBe(500);
});

it('does not record anything for requests outside Cashier webhook routes', function () {
    Route::post('other/route', fn () => response('ok'));

    $this->postJson('other/route');

    expect(app(WebhookCaptureContext::class)->failure())->toBeNull()
        ->and(app(WebhookCaptureContext::class)->terminatedStatus())->toBeNull();
});

it('identifies a matched Cashier webhook route via CashierWebhookRoute', function () {
    expect(CashierWebhookRoute::matches(null))->toBeFalse();
});
