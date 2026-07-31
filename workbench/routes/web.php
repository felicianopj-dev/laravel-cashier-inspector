<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

Route::get('/', function () {
    return view('welcome');
});

// No STRIPE_WEBHOOK_SECRET is configured, so signature verification is
// skipped — fine for local manual testing of Cashier Inspector. CSRF is
// excluded because Stripe's real requests never carry a Laravel session
// token, matching how Cashier's docs recommend registering this route.
Route::withoutMiddleware(ValidateCsrfToken::class)
    ->post('stripe/webhook', [WebhookController::class, 'handleWebhook']);
