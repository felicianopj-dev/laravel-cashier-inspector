<?php

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\BillableResolver;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;
use Workbench\App\Models\User;

afterEach(function () {
    Cashier::useCustomerModel('App\Models\User');
});

/** A customer model whose table has no `email` column. */
class CustomerWithoutEmail extends Model
{
    protected $table = 'cashier_inspector_events';
}

it('returns nulls when no customer id is given', function () {
    expect((new BillableResolver)->resolve(null))->toBe([
        'billable_type' => null,
        'billable_id' => null,
    ]);
});

it('resolves the billable model when a matching stripe_id exists', function () {
    Cashier::useCustomerModel(User::class);

    $user = User::create([
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'secret',
    ]);
    $user->forceFill(['stripe_id' => 'cus_resolvable'])->save();

    expect((new BillableResolver)->resolve('cus_resolvable'))->toBe([
        'billable_type' => User::class,
        'billable_id' => $user->id,
    ]);
});

it('returns nulls when no billable model matches the customer id', function () {
    Cashier::useCustomerModel(User::class);

    expect((new BillableResolver)->resolve('cus_unknown'))->toBe([
        'billable_type' => null,
        'billable_id' => null,
    ]);
});

it('matches nothing when the customer model has no email column', function () {
    // Regression: SQLite reads an unknown double-quoted identifier as a
    // string literal, so an unguarded `where('email', 'like', ...)` here
    // compares the term against the word "email" itself — a term that is
    // a substring of it matches every row in the table instead of failing.
    InspectorEvent::create([
        'stripe_event_id' => 'evt_no_email_column',
        'stripe_event_type' => 'customer.updated',
        'livemode' => false,
    ]);

    Cashier::useCustomerModel(CustomerWithoutEmail::class);

    expect((new BillableResolver)->idsMatchingEmail('mai'))->toBeNull();
});

it('returns nulls instead of throwing when the customer model class does not exist', function () {
    Cashier::useCustomerModel('App\Models\User');

    expect((new BillableResolver)->resolve('cus_whatever'))->toBe([
        'billable_type' => null,
        'billable_id' => null,
    ]);
});
