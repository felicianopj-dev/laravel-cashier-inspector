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
