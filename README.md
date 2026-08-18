# Laravel Cashier Inspector

> The diagnostic toolkit for Laravel Cashier.

**Early release.** The package is feature complete for what it sets out to
do, but it has not yet been used widely against real Stripe traffic, so the
API may still change in a minor release while the version stays below
`1.0.0`. See the [changelog](CHANGELOG.md) for what each version contains.

Laravel Cashier Inspector is a local debugging and diagnostic dashboard for
Laravel Cashier. It captures Stripe webhook processing, detects
inconsistent billing states, and explains what went wrong.

## Requirements

* PHP `^8.2`
* Laravel `^11.0 | ^12.0 | ^13.0`
* Laravel Cashier Stripe `^15.0 | ^16.0`

Cashier is a hard requirement rather than something you are expected to have
already, so Composer will refuse to install this package alongside a Cashier
version it does not support instead of failing later at runtime.

Every supported Laravel version is covered by the test matrix, against both
lowest and stable dependency resolutions: Laravel 11 and 12 on PHP 8.2, 8.3,
and 8.4, and Laravel 13 on PHP 8.3 and 8.4. Laravel 13 is not tested on PHP
8.2 because it requires a Pest Laravel plugin major that itself requires PHP
8.3; the package still supports PHP 8.2 on Laravel 11 and 12. Both Cashier
majors are covered by the same runs: the lowest resolutions land on Cashier 15
and the stable ones on Cashier 16.

## Installation

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

## Processing timeline

Every event page carries a timeline of the phases of the request that
delivered it, with how long each one took:

```
Request received   12:22:07.100   13 ms   Signature accepted.
Event captured     12:22:07.113   10 ms   Billable model resolved: App\Models\User #1.
Cashier handler    12:22:07.123    7 ms   Cashier handled customer.subscription.updated.
Diagnostics        12:22:07.130    8 ms   12 rules ran, 2 findings recorded.
Response           12:22:07.138    0 ms   HTTP 200, recorded as handled.
```

These five are the phases that can honestly be observed from outside
Cashier. Its webhook controller dispatches an event, calls its handler, and
dispatches another event, so everything the handler does is a single window
rather than a breakdown - and any listeners your own application registers
on those events fall inside that window too. When Cashier has no handler for
the event type, the handler phase is recorded as skipped; when it throws, as
failed, carrying the exception.

Phases are buffered and written in one insert per delivery. Turn the whole
thing off with `CASHIER_INSPECTOR_RECORD_STEPS=false` on an installation
with heavy webhook traffic that never reads a timeline. Deliveries recorded
while it is off, or before this feature existed, still show their received
and resolved times.

Every event page includes a "Copy diagnostic report" button that generates
a sanitized, plain-text summary suitable for pasting into a GitHub issue,
Discord, support, or an LLM.

## Telescope

When [Laravel Telescope](https://laravel.com/docs/telescope) is installed,
every entry it records while a Stripe webhook is being processed - the
request, the queries, the events, the exceptions - is tagged with that
event's id, and each event page carries a "View in Telescope" link filtered
to those entries. So a diagnosis here leads straight to the queries that ran
while it happened.

Telescope is not a dependency and nothing here runs without it. The link is
hidden when Telescope is absent or disabled, and
`CASHIER_INSPECTOR_TELESCOPE_LINKS=false` suppresses it while leaving
Telescope alone.

Nightwatch is not integrated. It exposes no supported way to attach an
identifier to the current request, and no per-request URL that could be
linked to, so anything built for it would depend on internals that are free
to change. If that changes, this is the place it would go.

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

## Diagnostic rules

Every captured event is run through a set of rules. A rule that triggers
records a finding against the event, which then shows on the event page, in
the copied diagnostic report, and in `cashier-inspector:check`. Warning and
error findings also make the event count as a problem, so it stays in the
dashboard's default view.

These rules ship with the package:

| Rule | Code | Severity | Triggers when |
| --- | --- | --- | --- |
| `ProcessingExceptionRule` | `processing_exception` | error | Cashier threw while handling the webhook |
| `IncompatibleCashierSchemaRule` | `cashier_schema_incompatible` | error | Cashier's own tables or columns are missing |
| `MissingWebhookSecretRule` | `webhook_secret_missing` | warning | `STRIPE_WEBHOOK_SECRET` is not configured |
| `DuplicateDeliveryRule` | `duplicate_delivery` | warning | The same Stripe event was delivered more than once |
| `TestLiveModeMismatchRule` | `mode_mismatch` | warning | The event's mode does not match the configured Stripe key |
| `MissingLocalSubscriptionRule` | `missing_local_subscription` | warning | The event names a subscription Cashier has no row for |
| `MissingBillableModelRule` | `missing_billable_model` | warning | The event's customer resolves to no local billable model |
| `DuplicateSubscriptionTypeRule` | `duplicate_subscription_type` | warning | One billable model has two valid subscriptions of the same type |
| `SlowProcessingRule` | `slow_processing` | warning | Processing took longer than the configured threshold |
| `SubscriptionStatusMismatchRule` | `subscription_status_mismatch` | warning | The local subscription status differs from Stripe's |
| `SubscriptionPriceMismatchRule` | `subscription_price_mismatch` | warning | The local subscription prices differ from Stripe's |
| `UnhandledWebhookRule` | `webhook_unmatched` | info | Cashier has no handler for this event type |

The last two make a live Stripe API call and are off by default. See Security
and privacy below.

### Writing your own rule

A rule implements `Contracts\DiagnosticRule`, which has two methods:
`supports()` decides whether the rule applies to an event at all, and
`diagnose()` returns a `DiagnosticResult`.

```php
namespace App\CashierInspector;

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

class RefundOnNewSubscriptionRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $event->stripe_event_type === 'charge.refunded'
            && $event->billable_id !== null;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        // The local model the event's Stripe customer resolved to. It is
        // stored as a type and an id rather than exposed as a relation.
        $billable = $event->billable_type::find($event->billable_id);

        $subscription = $billable?->subscriptions()
            ->where('created_at', '>', now()->subDays(7))
            ->first();

        if (! $subscription) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'refund_on_new_subscription',
            title: 'Refund on a week-old subscription',
            message: 'This customer was refunded within a week of subscribing.',
            suggestedChecks: [
                'Confirm the subscription was cancelled as well as refunded.',
            ],
            context: [
                'subscription_id' => $subscription->stripe_id,
            ],
        );
    }
}
```

Register it in the published config. Rules are resolved through the
container, so a rule may type-hint its dependencies in a constructor:

```php
'diagnostics' => [
    'rules' => [
        // ... the built-in rules you want to keep
        \App\CashierInspector\RefundOnNewSubscriptionRule::class,
    ],
],
```

The list is the whole story: remove a built-in rule from it and that rule
stops running. Order does not matter, since every rule is run against every
event it supports.

`DiagnosticResult` has five constructors. `passed()` and `skipped()` record
nothing. `info()`, `warning()` and `error()` each take a `code` (a short
stable identifier, unique to your rule), a `title`, a `message`, and
optionally `suggestedChecks` and a `context` array, both of which are shown
on the event page and included in the copied report.

Four things worth knowing before you write one:

* **Rules run synchronously, inside the request that handles the webhook.**
  Slow work here delays the response Cashier returns to Stripe. Anything that
  makes a network call should be behind a config flag that defaults to off,
  the way the two Stripe comparison rules are.
* **`$event->payload` is null unless payload storage is enabled**, which it
  is not outside local environments by default. A rule that reads the payload
  must handle its absence. Stored payloads are also redacted, so sensitive
  values read back as `[redacted]` rather than their originals. The extracted
  columns (`customer_id`, `subscription_id`, `invoice_id`,
  `checkout_session_id`, `livemode`, `billable_type`, `billable_id`) are
  always populated and are the safer thing to read.
* **A rule that throws is logged and skipped**, and the remaining rules still
  run. Nothing a rule does can break Cashier's webhook handling, so a failure
  is silent apart from the log entry.
* **Diagnostics are recomputed, not accumulated.** Every run replaces an
  event's findings, so a rule should reach the same conclusion given the same
  event rather than counting how often it has run.

If your rule reports on the application's configuration rather than on the
event itself, implement the `Contracts\EnvironmentDiagnostic` marker
interface as well. Findings from those rules still show in full on the event
page, but they do not make every event count as a problem in the dashboard's
default view:

```php
class MyEnvironmentRule implements DiagnosticRule, EnvironmentDiagnostic
```

## Health check

```bash
php artisan cashier-inspector:check
```

Reports whether the pieces this package depends on are in place: Cashier and
its schema, Cashier Inspector's own tables, the Stripe secret and webhook
secret, Cashier's billable customer model, whether any webhook events have
arrived recently, and what the diagnostic rules have already found on the
events still stored.

The command exits non-zero only when something is genuinely broken, so it
can gate a deploy. A missing webhook secret is reported as a warning and
still exits zero — that is a normal state in local development, and a check
that failed on it would just get switched off.

The recent-events window defaults to 24 hours and is configurable with
`CASHIER_INSPECTOR_RECENT_EVENTS_WINDOW_HOURS`, for applications that only
see Stripe traffic occasionally.

## Inspecting one event

```bash
php artisan cashier-inspector:event evt_123
```

Prints what was captured and diagnosed for a single Stripe event, followed
by every delivery attempt with its status, severity, and duration. The
summary is the same text the dashboard's "Copy diagnostic report" button
produces, so an event described from a terminal and one pasted from a
browser read identically, and neither includes the raw payload.

Useful where the dashboard is not reachable — an SSH session on a
production box, or a CI job. Exits non-zero when no event was captured for
that id.

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

## Troubleshooting

[TROUBLESHOOTING.md](TROUBLESHOOTING.md) covers the recurring Cashier and
Stripe webhook failures: what each looks like in the dashboard, what causes
it, and how to fix it. Each section names the finding this package records,
so a diagnosis on an event page leads straight to the explanation.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what shipped in each release.

## Disclaimer

Laravel Cashier Inspector is an independent open-source project and is not
affiliated with or endorsed by Laravel LLC or Stripe, Inc.

## License

MIT
