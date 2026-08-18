<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    // Read through env() rather than app()->environment(): once published,
    // this file is loaded during bootstrap, before the container binds "env".
    'enabled' => env(
        'CASHIER_INSPECTOR_ENABLED',
        env('APP_ENV', 'production') === 'local'
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
    |
    | Dot-paths masked out of a payload before it is stored. Segments may be
    | "*" to match every key at that level. The defaults cover the personal
    | data Stripe puts on the object shapes Cashier receives: checkout
    | sessions and invoices (customer_email, customer_details), the customer
    | object itself (email, name, phone, address), and charges and payment
    | intents (receipt_email, billing_details). "data.previous_attributes"
    | carries the old values of whatever changed, so the same fields are
    | masked there too.
    |
    | Some of these keys are not always personal data: "name" is a product
    | or price name on product.* and price.* events, for instance. The
    | default errs towards masking. Trim this list if a payload you need to
    | read is coming back over-redacted.
    */

    'redaction' => [
        'enabled' => env('CASHIER_INSPECTOR_REDACTION_ENABLED', true),

        'paths' => [
            'data.object.customer_email',
            'data.object.customer_details',
            'data.object.metadata',
            'data.object.email',
            'data.object.name',
            'data.object.phone',
            'data.object.address',
            'data.object.shipping',
            'data.object.receipt_email',
            'data.object.billing_details',
            'data.previous_attributes.email',
            'data.previous_attributes.name',
            'data.previous_attributes.phone',
            'data.previous_attributes.address',
            'data.previous_attributes.shipping',
            'data.previous_attributes.metadata',
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
            env('APP_ENV', 'production') === 'local'
        ),

        'store_exception_traces' => env('CASHIER_INSPECTOR_STORE_EXCEPTION_TRACES', false),

        'retention_days' => env('CASHIER_INSPECTOR_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing timeline
    |--------------------------------------------------------------------------
    |
    | Records the phases of each webhook request - when it arrived, when this
    | package captured it, how long Cashier's handler ran, how long the
    | diagnostic rules took - and shows them on the event page. The phases are
    | buffered and written in one insert per delivery. Turn this off on an
    | installation with heavy webhook traffic that never reads a timeline.
    |
    */

    'steps' => [
        'enabled' => env('CASHIER_INSPECTOR_RECORD_STEPS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health check
    |--------------------------------------------------------------------------
    |
    | How far back `cashier-inspector:check` looks for received webhook events
    | before reporting that none have arrived. An application that only sees
    | Stripe traffic now and then can widen the window so a quiet day is not
    | reported as a problem.
    |
    */

    'health' => [
        'recent_events_window_hours' => env('CASHIER_INSPECTOR_RECENT_EVENTS_WINDOW_HOURS', 24),
    ],

];
