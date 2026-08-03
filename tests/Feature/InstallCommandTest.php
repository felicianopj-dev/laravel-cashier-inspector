<?php

/**
 * vendor:publish writes real, uniquely-timestamped files to disk on every
 * call (Laravel doesn't skip re-publishing migrations just because an
 * equivalent one already exists), so every test in this file must clean up
 * after itself or repeated runs pile up duplicate migrations that break
 * later `migrate` calls, in this suite and in the workbench.
 */
afterEach(function () {
    @unlink(config_path('cashier-inspector.php'));

    foreach (glob(database_path('migrations/*_create_cashier_inspector_*')) as $file) {
        @unlink($file);
    }
});

it('publishes the config and migrations', function () {
    $this->artisan('cashier-inspector:install')->assertSuccessful();

    expect(file_exists(config_path('cashier-inspector.php')))->toBeTrue();
    expect(glob(database_path('migrations/*_create_cashier_inspector_events_table.php')))->not->toBeEmpty();
});

it('confirms the webhook secret is configured', function () {
    config()->set('cashier.webhook.secret', 'whsec_test');

    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET is configured.')
        ->assertSuccessful();
});

it('warns when the webhook secret is missing', function () {
    config()->set('cashier.webhook.secret', null);

    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET is not set.')
        ->assertSuccessful();
});

it('confirms Cashier is installed', function () {
    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain('Laravel Cashier Stripe is installed.')
        ->assertSuccessful();
});

it('confirms Cashier\'s schema looks complete', function () {
    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain('Cashier\'s database schema looks complete.')
        ->assertSuccessful();
});

it('warns when Cashier\'s schema is missing a table', function () {
    Illuminate\Support\Facades\Schema::drop('subscription_items');

    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain('subscription_items table')
        ->assertSuccessful();
});

it('prints the dashboard URL', function () {
    config()->set('cashier-inspector.path', 'my-inspector');

    $this->artisan('cashier-inspector:install')
        ->expectsOutputToContain(url('my-inspector'))
        ->assertSuccessful();
});
