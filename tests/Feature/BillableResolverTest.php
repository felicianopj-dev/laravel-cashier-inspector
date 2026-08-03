<?php

use FelicianoPJ\CashierInspector\Support\BillableResolver;
use Laravel\Cashier\Cashier;
use Workbench\App\Models\User;

afterEach(function () {
    Cashier::useCustomerModel('App\Models\User');
});

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

it('returns nulls instead of throwing when the customer model class does not exist', function () {
    Cashier::useCustomerModel('App\Models\User');

    expect((new BillableResolver)->resolve('cus_whatever'))->toBe([
        'billable_type' => null,
        'billable_id' => null,
    ]);
});
