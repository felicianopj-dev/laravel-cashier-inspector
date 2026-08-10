# Laravel Cashier Inspector

> The diagnostic toolkit for Laravel Cashier.

**Early release.** `v0.1.0` is the first tagged version. It is feature
complete for what it sets out to do, but it has not yet been used widely
against real Stripe traffic, so the API may still change in a minor
release while the version stays below `1.0.0`.

Laravel Cashier Inspector is a local debugging and diagnostic dashboard for
Laravel Cashier. It captures Stripe webhook processing, detects
inconsistent billing states, and explains what went wrong.

## Requirements

* PHP `^8.2`
* Laravel `^11.0 | ^12.0 | ^13.0`
* Laravel Cashier Stripe `^15.0`

Laravel 11 and 12 are covered by the test matrix, on PHP 8.2, 8.3, and 8.4,
against both lowest and stable dependency resolutions. Laravel 13 is
allowed and Cashier supports it, but it is not tested yet: the Pest Laravel
plugin only gained Laravel 13 support in a major that requires PHP 8.3,
which the matrix's PHP 8.2 lanes cannot install.

## Installation

Not on Packagist yet. Add the repository to your `composer.json` first:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/felicianopj-dev/laravel-cashier-inspector"
    }
]
```

Then:

```bash
composer require felicianopj/laravel-cashier-inspector:^0.1 --dev
php artisan cashier-inspector:install
php artisan migrate
```

The install command publishes the package config and migrations, checks
that Laravel Cashier Stripe and its own database schema are present, warns
if `STRIPE_WEBHOOK_SECRET` isn't set, and prints the dashboard URL.

No published views or frontend assets are required for normal installation.
Laravel's package discovery registers the service provider automatically.

To publish config or migrations individually:

```bash
php artisan vendor:publish --tag=cashier-inspector-config
php artisan vendor:publish --tag=cashier-inspector-migrations
```

## Dashboard

The dashboard lives at `/cashier-inspector` by default, configurable via
`CASHIER_INSPECTOR_PATH`. It's enabled by default only in local
environments (`CASHIER_INSPECTOR_ENABLED`).

By default it shows problems only — errors, warnings, unmatched events, and
processing that's taking too long — with filters and search to see
everything else.

Search matches Stripe event, customer, subscription, invoice, and checkout
session ids, the local billable model id, and the local billable email.
Email searching works even though emails are redacted out of stored
payloads: the term is matched against your own customer table, so no copy
of the address has to be kept here. Only the model configured through
Cashier (`Cashier::useCustomerModel()`) is searched, on its `email` column.

Every event page includes a "Copy diagnostic report" button that generates
a sanitized, plain-text summary suitable for pasting into a GitHub issue,
Discord, support, or an LLM.

## Security and privacy

The dashboard is never public by default. In production, it stays disabled
until you explicitly enable it, and you must supply an authorization
callback:

```php
use FelicianoPJ\CashierInspector\CashierInspector;

CashierInspector::auth(function (Illuminate\Http\Request $request): bool {
    return $request->user()?->can('viewCashierInspector') ?? false;
});
```

Without a callback, access is restricted to the local environment.

Webhook payloads can contain personally identifiable and commercially
sensitive data. By default, the following paths are redacted before a
payload is ever stored:

* `data.object.customer_email`
* `data.object.customer_details`
* `data.object.metadata`
* `data.object.email`
* `data.object.name`
* `data.object.phone`
* `data.object.address`
* `data.object.shipping`
* `data.object.receipt_email`
* `data.object.billing_details`
* `data.previous_attributes.email`
* `data.previous_attributes.name`
* `data.previous_attributes.phone`
* `data.previous_attributes.address`
* `data.previous_attributes.shipping`
* `data.previous_attributes.metadata`

These cover the personal data Stripe puts on the object shapes Cashier
receives: checkout sessions and invoices, the customer object itself, and
charges and payment intents. `data.previous_attributes` carries the old
values of whatever changed, so the same fields are masked there too. Note
that a few of these keys are not always personal data — `name` is a
product or price name on `product.*` and `price.*` events, for instance —
and the default errs towards masking.

Redaction paths are configurable and support dot-notation with wildcards.
It can also be disabled entirely via `CASHIER_INSPECTOR_REDACTION_ENABLED`,
though that isn't recommended outside local development.

Raw payload storage itself is also environment-conditional
(`CASHIER_INSPECTOR_STORE_PAYLOADS`): on by default in local environments,
off by default everywhere else. Exception stack traces are never stored by
default (`CASHIER_INSPECTOR_STORE_EXCEPTION_TRACES`).

Cashier Inspector never modifies Laravel Cashier's own tables — it only
reads from them for diagnostics, and writes to its own
`cashier_inspector_*` tables.

Two diagnostic rules (local/Stripe subscription status and price mismatch)
compare local Cashier state against a live fetch from Stripe. This is
opt-in and off by default (`CASHIER_INSPECTOR_STRIPE_API_CHECKS`), since
enabling it makes a live Stripe API call — synchronously, during webhook
processing — using your configured Stripe credentials, which introduces a
network dependency and consumes API quota.

## Retention

Old events, deliveries, and diagnostics can be pruned:

```bash
php artisan cashier-inspector:prune
```

This isn't scheduled automatically. Add it to your application's own
scheduler if you want it to run regularly:

```php
$schedule->command('cashier-inspector:prune')->daily();
```

The retention period defaults to 7 days (`CASHIER_INSPECTOR_RETENTION_DAYS`)
and can be overridden per run with `--days`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what shipped in each release.

## Disclaimer

Laravel Cashier Inspector is an independent open-source project and is not
affiliated with or endorsed by Laravel LLC or Stripe, Inc.

## License

MIT
