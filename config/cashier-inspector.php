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
        ],

        'slow_processing_threshold_ms' => env('CASHIER_INSPECTOR_SLOW_PROCESSING_THRESHOLD_MS', 5000),
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
