<?php

use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

$sampleStripePayload = fn (): array => [
    'id' => 'evt_123',
    'type' => 'customer.subscription.updated',
    'api_version' => '2024-06-20',
    'livemode' => false,
    'data' => [
        'object' => [
            'id' => 'sub_123',
            'customer' => 'cus_123',
        ],
    ],
];

it('captures the webhook when WebhookReceived is dispatched', function () use ($sampleStripePayload) {
    Carbon::setTestNow('2026-01-01 00:00:00');

    event(new WebhookReceived($sampleStripePayload()));

    $capture = app(WebhookCaptureContext::class)->current();

    expect($capture)->not->toBeNull()
        ->and($capture->stripeEventId)->toBe('evt_123')
        ->and($capture->stripeEventType)->toBe('customer.subscription.updated')
        ->and($capture->stripeApiVersion)->toBe('2024-06-20')
        ->and($capture->livemode)->toBeFalse()
        ->and($capture->receivedAt->equalTo(Carbon::now()))->toBeTrue()
        ->and($capture->handledAt)->toBeNull();

    Carbon::setTestNow();
});

it('marks the capture as handled and computes duration when WebhookHandled is dispatched', function () use ($sampleStripePayload) {
    Carbon::setTestNow('2026-01-01 00:00:00');
    event(new WebhookReceived($sampleStripePayload()));

    Carbon::setTestNow('2026-01-01 00:00:00.250');
    event(new WebhookHandled($sampleStripePayload()));

    $capture = app(WebhookCaptureContext::class)->current();

    expect($capture->handledAt)->not->toBeNull()
        ->and($capture->durationMs)->toBe(250);

    Carbon::setTestNow();
});

it('does not mark the capture as handled if only WebhookReceived fires', function () use ($sampleStripePayload) {
    event(new WebhookReceived($sampleStripePayload()));

    $capture = app(WebhookCaptureContext::class)->current();

    expect($capture->handledAt)->toBeNull()
        ->and($capture->durationMs)->toBeNull();
});
