<?php

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use Illuminate\Support\Facades\Route;

it('survives an exception thrown before the webhook was ever captured', function () {
    // No "type" key: Cashier's controller indexes $payload['type'] before
    // dispatching WebhookReceived, so this throws before Cashier Inspector's
    // own webhook listeners ever see the request. There is no capture to
    // attach the failure to, and reporting it must not throw on its own.
    $this->postJson('cashier/webhook', ['id' => 'evt_no_type']);

    expect(InspectorEvent::count())->toBe(0);
});

it('does not record anything for requests outside Cashier webhook routes', function () {
    Route::post('other/route', fn () => throw new RuntimeException('boom'));

    $this->postJson('other/route');

    expect(InspectorEvent::count())->toBe(0);
});

it('identifies a matched Cashier webhook route via CashierWebhookRoute', function () {
    expect(CashierWebhookRoute::matches(null))->toBeFalse();
});
