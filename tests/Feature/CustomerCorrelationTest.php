<?php

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Workbench\App\Models\User;

$invoicePaid = fn (string $eventId = 'evt_invoice_paid'): array => [
    'id' => $eventId,
    'type' => 'invoice.paid',
    'livemode' => false,
    'data' => ['object' => [
        'id' => 'in_123',
        'object' => 'invoice',
        'customer' => 'cus_123',
    ]],
];

// An invoice_payment names its invoice and nothing else: no customer field
// exists on the object at all.
$invoicePaymentPaid = fn (string $eventId = 'evt_invoice_payment_paid'): array => [
    'id' => $eventId,
    'type' => 'invoice_payment.paid',
    'livemode' => false,
    'data' => ['object' => [
        'id' => 'inpay_123',
        'object' => 'invoice_payment',
        'invoice' => 'in_123',
    ]],
];

it('records the invoice a non-invoice object references', function () use ($invoicePaymentPaid) {
    event(new WebhookReceived($invoicePaymentPaid()));

    expect(InspectorEvent::where('stripe_event_id', 'evt_invoice_payment_paid')->sole()->invoice_id)
        ->toBe('in_123');
});

it('correlates the customer through an invoice seen on an earlier event', function () use ($invoicePaid, $invoicePaymentPaid) {
    event(new WebhookReceived($invoicePaid()));
    event(new WebhookReceived($invoicePaymentPaid()));

    expect(InspectorEvent::where('stripe_event_id', 'evt_invoice_payment_paid')->sole()->customer_id)
        ->toBe('cus_123');
});

it('leaves the customer null when there is nothing to correlate through', function () {
    event(new WebhookReceived($invoicePaid = [
        'id' => 'evt_known_customer',
        'type' => 'invoice.paid',
        'livemode' => false,
        'data' => ['object' => ['id' => 'in_999', 'object' => 'invoice', 'customer' => 'cus_999']],
    ]));

    // A charge with no customer and no invoice must not inherit the
    // customer of an unrelated event that happens to be stored.
    event(new WebhookReceived([
        'id' => 'evt_orphan_charge',
        'type' => 'charge.updated',
        'livemode' => false,
        'data' => ['object' => ['id' => 'ch_123', 'object' => 'charge']],
    ]));

    expect(InspectorEvent::where('stripe_event_id', 'evt_orphan_charge')->sole()->customer_id)
        ->toBeNull();
});

it('backfills a customer that arrived after the event that needed it', function () use ($invoicePaid, $invoicePaymentPaid) {
    // Reverse order: the invoice_payment lands first, so nothing can be
    // correlated at capture time.
    event(new WebhookReceived($invoicePaymentPaid()));
    event(new WebhookReceived($invoicePaid()));

    $event = InspectorEvent::where('stripe_event_id', 'evt_invoice_payment_paid')->sole();
    expect($event->customer_id)->toBeNull();

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    expect($event->fresh()->customer_id)->toBe('cus_123');
});

it('backfills the billable model for a stripe_id set after the events arrived', function () use ($invoicePaid) {
    Cashier::useCustomerModel(User::class);

    event(new WebhookReceived($invoicePaid()));

    $event = InspectorEvent::where('stripe_event_id', 'evt_invoice_paid')->sole();
    expect($event->billable_id)->toBeNull();

    $user = User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret']);
    $user->forceFill(['stripe_id' => 'cus_123'])->save();

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    expect($event->fresh()->billable_id)->toBe($user->id)
        ->and($event->fresh()->billable_type)->toBe(User::class);

    Cashier::useCustomerModel('App\Models\User');
});
