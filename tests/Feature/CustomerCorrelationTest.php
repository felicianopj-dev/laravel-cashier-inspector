<?php

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Facades\DB;
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

it('recovers correlation ids from a payload stored before they were read', function () use ($invoicePaid, $invoicePaymentPaid) {
    // What an upgrade looks like: the rows were written by a version that
    // read only the nested reference fields, so a customer object's own id
    // and an invoice_payment's invoice were never recorded, and every
    // correlation column is blank.
    InspectorEvent::create([
        'stripe_event_id' => 'evt_customer_updated',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
        'payload' => [
            'id' => 'evt_customer_updated',
            'type' => 'customer.updated',
            'data' => ['object' => ['id' => 'cus_123', 'object' => 'customer']],
        ],
    ]);

    InspectorEvent::create([
        'stripe_event_id' => 'evt_invoice_payment_paid',
        'stripe_event_type' => 'invoice_payment.paid',
        'livemode' => false,
        'payload' => $invoicePaymentPaid(),
    ]);

    // A sibling carrying the invoice, so the recovered invoice_id has
    // something to correlate the customer through.
    event(new WebhookReceived($invoicePaid()));

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    $customer = InspectorEvent::where('stripe_event_id', 'evt_customer_updated')->sole();
    $payment = InspectorEvent::where('stripe_event_id', 'evt_invoice_payment_paid')->sole();

    expect($customer->customer_id)->toBe('cus_123')
        ->and($payment->invoice_id)->toBe('in_123')
        ->and($payment->customer_id)->toBe('cus_123');
});

it('leaves a row alone when no payload was stored to recover from', function () {
    InspectorEvent::create([
        'stripe_event_id' => 'evt_no_payload',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
        'payload' => null,
    ]);

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    expect(InspectorEvent::where('stripe_event_id', 'evt_no_payload')->sole()->customer_id)
        ->toBeNull();
});

it('does not recover a correlation id the redaction masked out', function () {
    // Recovery reads a payload that was already redacted on the way in, so
    // a correlation path added to redaction.paths would otherwise write the
    // mask itself into an indexed, searchable column.
    config()->set('cashier-inspector.redaction.paths', ['data.object.customer']);

    InspectorEvent::create([
        'stripe_event_id' => 'evt_redacted_customer',
        'stripe_event_type' => 'invoice.paid',
        'livemode' => false,
        'payload' => app(FelicianoPJ\CashierInspector\Redaction\PayloadRedactor::class)->redact([
            'id' => 'evt_redacted_customer',
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_777', 'object' => 'invoice', 'customer' => 'cus_777']],
        ]),
    ]);

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    $event = InspectorEvent::where('stripe_event_id', 'evt_redacted_customer')->sole();

    expect($event->customer_id)->toBeNull()
        ->and($event->invoice_id)->toBe('in_777');
});

it('survives a row whose stored payload is not an object', function () {
    InspectorEvent::create([
        'stripe_event_id' => 'evt_corrupt_payload',
        'stripe_event_type' => 'invoice.paid',
        'livemode' => false,
    ]);

    // Bypasses the model's array cast, which is the only thing that would
    // normally keep a scalar out of this column.
    DB::table('cashier_inspector_events')
        ->where('stripe_event_id', 'evt_corrupt_payload')
        ->update(['payload' => '"not an object"']);

    $this->artisan('cashier-inspector:backfill-customers')->assertSuccessful();

    expect(InspectorEvent::where('stripe_event_id', 'evt_corrupt_payload')->sole()->customer_id)
        ->toBeNull();
});
