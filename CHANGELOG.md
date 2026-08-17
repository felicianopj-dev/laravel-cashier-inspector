# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version stays below 1.0.0, breaking changes may land in a minor
release.

## Unreleased

### Added

* A command to inspect one event, `php artisan cashier-inspector:event evt_123`.
  It prints the same report the dashboard's copy button produces, followed by
  every delivery attempt with its status, severity, and duration, and exits
  non-zero when no event was captured for that id. The dashboard was the only
  way to read a captured event, which is no help over SSH on a machine whose
  dashboard is not reachable.

* Documentation for the diagnostic rules: what each of the twelve built-in
  rules detects and the code it records, and how to write and register a rule
  of your own. The extension point itself already worked - a rule is a class
  implementing a two-method interface, added to the `diagnostics.rules` config
  array and resolved through the container - but nothing described it, so it
  was effectively private. A test now exercises the documented example
  verbatim, so the contract cannot change without the documentation changing
  with it.

* A health check command, `php artisan cashier-inspector:check`. It reports
  Cashier and its schema, this package's own tables, the Stripe secret and
  webhook secret, Cashier's billable customer model, whether any webhook
  events arrived recently, and what the diagnostic rules already found on the
  events still stored. It exits non-zero only on conditions that actually
  break the package, so warnings such as a missing webhook secret in local
  development do not fail a deployment. The recent-events window defaults to
  24 hours and is configurable with
  `CASHIER_INSPECTOR_RECENT_EVENTS_WINDOW_HOURS`.

### Changed

* The package is published on Packagist, so installing it is a plain
  `composer require`. Until now every application had to add a version control
  repository entry to its own `composer.json` before Composer could find the
  package at all; that entry is no longer needed and can be removed.

## v0.1.2 - 2026-08-12

### Changed

* The dashboard's severity column now shows the worst of a delivery's own
  outcome and the findings on its event, instead of the delivery's outcome
  alone. Cashier handles most problem events without complaint, so the row was
  recorded as a success or as plain information and the list gave no hint that
  anything had been diagnosed - an event kept by the problems-only view looked
  identical to a healthy one. Findings from rules that describe the
  installation are still excluded, exactly as they are when deciding whether an
  event is a problem at all. Sorting and filtering by severity follow the same
  value, so a row shown as a warning is found by filtering for warnings and
  sorts as one.

* Laravel Cashier Stripe is now a hard requirement, at `^15.0 | ^16.0`, rather
  than a development dependency the package quietly assumed was present. Since
  nothing declared it, installing into an application pulled no Cashier at all
  and constrained no version, so a fresh install could sit on a Cashier major
  the package had never been tested against. Composer now refuses an
  unsupported combination up front. Cashier 16 support is new here and is
  covered by the existing test matrix: the lowest dependency resolutions land
  on Cashier 15 and the stable ones on Cashier 16, so both majors run on every
  supported Laravel and PHP version.

### Fixed

* Publishing the config file no longer takes the application down. Two of its
  defaults called `app()->environment('local')`, and a published config file is
  read during bootstrap, before the container binds `env` - so every artisan
  command and every request failed with "Target class [env] does not exist"
  from the moment `cashier-inspector:install` ran. Both defaults now read
  `APP_ENV` through `env()`, which is what a config file may safely do, and the
  behaviour is unchanged: the dashboard and payload storage still default to on
  only in a local environment. This affected v0.1.0 and v0.1.1; upgrade if you
  installed either. The package's own test suite could not catch it, because
  the service provider merges the config long after bootstrap, so the file is
  only loaded that early once published - a regression test now loads it
  against a container with no `env` binding.
* Running `cashier-inspector:install` more than once no longer duplicates the
  migrations. Publishing stamps a fresh timestamp onto each file and does not
  look for an earlier copy, so a second run left two migrations creating each
  table and the next `php artisan migrate` failed with "table already exists".
  Since the command is meant to be re-run after fixing what it reports, it now
  recognises its own earlier output and skips publishing.
* The warning about Cashier's missing schema now gives a sequence that works.
  It suggested `php artisan migrate` alone, but Cashier publishes its
  migrations rather than loading them, so the tables it names are never created
  that way. It now names the publish step first.

## v0.1.1 - 2026-08-12

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

* The dashboard and event pages were restyled. Both now extend a single
  layout view instead of each carrying its own copy of the stylesheet, so the
  markup and the CSS live in one place. The pages gained a sticky header, a
  centred content column, and card surfaces, and they follow the operating
  system's light or dark preference. Layout is responsive without media
  queries: the filter row and the event summary reflow from several columns
  down to one as the viewport narrows, and wide tables scroll inside their own
  card rather than widening the page. The diagnostic context column now wraps
  its JSON instead of scrolling. No published assets or build step were
  introduced, and no route, controller or query changed.
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
