<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'enabled' => env(
        'CASHIER_INSPECTOR_ENABLED',
        app()->environment('local')
    ),

    'path' => env('CASHIER_INSPECTOR_PATH', 'cashier-inspector'),

    'middleware' => [
        'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    */

    'polling' => [
        'enabled' => env('CASHIER_INSPECTOR_POLLING_ENABLED', true),

        'interval_ms' => env('CASHIER_INSPECTOR_POLLING_INTERVAL_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    |
    | FQCNs of FelicianoPJ\CashierInspector\Contracts\DiagnosticRule
    | implementations, run against every event after each delivery attempt
    | resolves. Add your own classes here to extend the engine.
    */

    'diagnostics' => [
        'rules' => [
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingWebhookSecretRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\ProcessingExceptionRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\UnhandledWebhookRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateDeliveryRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\TestLiveModeMismatchRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingLocalSubscriptionRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\IncompatibleCashierSchemaRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\SlowProcessingRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\MissingBillableModelRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\DuplicateSubscriptionTypeRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\SubscriptionStatusMismatchRule::class,
            \FelicianoPJ\CashierInspector\Diagnostics\Rules\SubscriptionPriceMismatchRule::class,
        ],

        'slow_processing_threshold_ms' => env('CASHIER_INSPECTOR_SLOW_PROCESSING_THRESHOLD_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe API checks
    |--------------------------------------------------------------------------
    |
    | Some diagnostic rules (subscription status/price mismatch) compare
    | local Cashier state against a live fetch from Stripe. Disabled by
    | default: it makes a network call to Stripe synchronously during
    | webhook processing, consumes API quota, and requires valid Stripe
    | credentials. Enable only if that trade-off is acceptable.
    */

    'stripe_api_checks' => [
        'enabled' => env('CASHIER_INSPECTOR_STRIPE_API_CHECKS', false),

        'timeout_seconds' => env('CASHIER_INSPECTOR_STRIPE_API_CHECKS_TIMEOUT_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    */

    'redaction' => [
        'enabled' => env('CASHIER_INSPECTOR_REDACTION_ENABLED', true),

        'paths' => [
            'data.object.customer_email',
            'data.object.customer_details',
            'data.object.metadata',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'store_payloads' => env(
            'CASHIER_INSPECTOR_STORE_PAYLOADS',
            app()->environment('local')
        ),

        'store_exception_traces' => env('CASHIER_INSPECTOR_STORE_EXCEPTION_TRACES', false),

        'retention_days' => env('CASHIER_INSPECTOR_RETENTION_DAYS', 7),
    ],

];
