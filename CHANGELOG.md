# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version stays below 1.0.0, breaking changes may land in a minor
release.

## Unreleased

### Added

* Sortable dashboard columns. Clicking a column header orders by it, first
  ascending, then flipping direction each time the active column is clicked
  again; choosing another column replaces the previous ordering. The active
  header shows the direction. Ordering composes with the filters and survives
  pagination, and no column means newest first, as before.

### Fixed

* The problems-only dashboard view now counts an event as a problem when a
  diagnostic rule flagged it, not only when the delivery row itself failed.
  Cashier handles most problem events without complaint - a duplicate
  delivery, a missing local subscription, a missing billable model, a
  test/live mode mismatch - so those were previously filtered out of the view
  meant to surface them, and only unmatched events showed up.
* Diagnostics from rules that describe the installation rather than the event
  no longer mark every event as a problem. A missing webhook secret or an
  incompatible Cashier schema is reported identically on every event, so
  before this a single missing environment variable turned the problems view
  into a list of everything ever received. Such rules now implement the new
  `EnvironmentDiagnostic` marker interface; their findings still appear on the
  event page and in the copied diagnostic report at full severity. Custom
  rules that check configuration rather than events can implement it too.
* Pagination no longer renders Laravel's Tailwind paginator view, whose inline
  SVG arrows are sized only by utility classes this dashboard does not ship,
  so they rendered at their intrinsic size at the bottom of the page. Replaced
  with plain previous/next links and a result count, styled with the
  dashboard's own CSS.

### Changed

* Laravel 13 is now covered by the test matrix, on PHP 8.3 and 8.4, against
  both lowest and stable dependency resolutions. This closes the known
  limitation noted in v0.1.0. The dev requirement on Pest widened to
  `^3.0|^4.0` so each matrix lane resolves whichever major its PHP version
  allows; Laravel 13 on PHP 8.2 is excluded, since the Pest Laravel plugin
  major that supports Laravel 13 requires PHP 8.3. The package's own PHP
  `^8.2` requirement is unchanged.

## v0.1.0 - 2026-08-10

First tagged release. Phase 1 MVP.

### Added

* Webhook capture. Listeners on Cashier's `WebhookReceived` and
  `WebhookHandled` events record one row per logical Stripe event and one row
  per observed delivery attempt, so a redelivered event keeps its history
  instead of overwriting it.
* Failure capture for problems Cashier never reports as handled: an exception
  reporting hook scoped to Cashier's webhook route, plus a terminating
  middleware as a fallback signal for requests that end abnormally or that
  Cashier had no handler for.
* Diagnostic engine with twelve rules covering a missing webhook secret,
  processing exceptions, unhandled event types, duplicate deliveries,
  test/live mode mismatches, missing local subscriptions, an incompatible
  Cashier schema, slow processing, missing billable models, duplicate Cashier
  subscription types, and (opt-in) local versus Stripe subscription status and
  price mismatches. Rules are resolved from config, so applications can add
  their own.
* Dashboard at `/cashier-inspector`, showing problems only by default, with an
  event detail page, a processing timeline, delivery attempts, and the stored
  redacted payload. Polling keeps the list current without a full reload.
* Search across Stripe event, customer, subscription, invoice, and checkout
  session ids, the local billable model id, and the local billable email.
  Email search resolves against the application's own customer table, so no
  copy of the address is stored here.
* Filters by status, severity, event type, test/live mode, customer,
  subscription, and received date range, shared between the dashboard and the
  polling endpoint.
* "Copy diagnostic report" on every event page, producing a sanitized
  plain-text summary that never includes the raw payload.
* Payload redaction before storage, with configurable dot-paths and wildcard
  support. Raw payload storage is on by default in local environments only,
  and exception stack traces are never stored by default.
* Dashboard authorization through `CashierInspector::auth()`. Without a
  callback, access is restricted to the local environment.
* `cashier-inspector:install` to publish config and migrations, verify Cashier
  and its schema, warn about a missing `STRIPE_WEBHOOK_SECRET`, and print the
  dashboard URL.
* `cashier-inspector:prune` to delete events past the retention period.
  Deliberately not scheduled automatically.

### Known limitations

* Laravel 13 is allowed by the package's constraints and Cashier supports it,
  but it is not covered by the test matrix: `pestphp/pest-plugin-laravel` only
  gained Laravel 13 support in a major that requires PHP 8.3, which the
  matrix's PHP 8.2 lanes cannot install. Laravel 11 and 12 are tested on PHP
  8.2, 8.3, and 8.4, against both lowest and stable dependency resolutions.
* The processing timeline records when an event was received and when it
  resolved, not a per-step breakdown of signature validation and handler
  dispatch.
