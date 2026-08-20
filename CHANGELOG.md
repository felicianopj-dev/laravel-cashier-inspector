# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version stays below 1.0.0, breaking changes may land in a minor
release.

## Unreleased

### Added

* A `cashier-inspector:backfill-customers` command, and automatic
  correlation on capture, for events that arrive with no customer of their
  own. Some Stripe objects reference none - an `invoice_payment` names only
  its invoice - so the customer is taken from another event for the same
  invoice or subscription. Correlating between events reads the recorded id
  columns rather than stored payloads, so it works where payload storage is
  off. The command also resolves billable models for events captured before
  a `stripe_id` was set, and re-reads stored payloads to recover ids from
  events captured before this package was reading them, which is what an
  existing installation needs after upgrading. It only ever fills blanks.

* An optional route instrumentation, off by default, behind
  `CASHIER_INSPECTOR_ROUTE_MIDDLEWARE=true`. It attaches middleware to
  Cashier's own webhook route so that every exception the controller lets
  escape is recorded with its class, message and trace - including ones your
  application never reports, which until now produced only a bare "ended with
  HTTP 500" record. The exception is rethrown unchanged, so Cashier and your
  error handling are unaffected, and the route is located by the controller
  it resolves to rather than by its path.

* A Telescope link on every event page. When Laravel Telescope is installed,
  everything it records while a Stripe webhook is being processed is tagged
  with that event's id, and the page links to those entries - so a finding
  here leads to the queries, events and exceptions that ran while it
  happened. Telescope is not a dependency: the link is hidden when it is
  absent or disabled, and `CASHIER_INSPECTOR_TELESCOPE_LINKS=false`
  suppresses it.

  Nightwatch was investigated and is deliberately not integrated. It exposes
  no supported way to attach an identifier to the current request and no
  per-request URL to link to.

* A processing timeline on every event page. Each delivery now records the
  phases of the request that carried it - when it arrived, what this package
  captured and which billable model it resolved, how long Cashier's own
  handler ran, how long the diagnostic rules took, and what went back to
  Stripe - with a duration for each. Until now the page showed two points,
  received and resolved, which said nothing about where a slow delivery
  spent its time or how far a failed one got. A handler Cashier has no
  method for is recorded as skipped, and one that throws as failed, carrying
  the exception.

  The phases are buffered and written in a single insert per delivery, and
  the whole feature can be turned off with
  `CASHIER_INSPECTOR_RECORD_STEPS=false`. Deliveries recorded before this
  release, or with recording off, keep showing their received and resolved
  times.

  This adds a `cashier_inspector_steps` table: run
  `php artisan cashier-inspector:install` to publish the migration, then
  `php artisan migrate`.

### Fixed

* Correlation ids are now read in both directions. An event whose object
  *is* the customer or the invoice recorded nothing in that column, and an
  event referencing an invoice it was not itself about recorded no invoice
  id either. `customer.updated` was the visible case: it stored no customer,
  so the dashboard column was empty and a search by customer id could not
  find it.

  This adds an index on `invoice_id`, which correlation now queries: run
  `php artisan cashier-inspector:install` to publish the migration, then
  `php artisan migrate`. Events recorded before the upgrade keep their empty
  columns until `php artisan cashier-inspector:backfill-customers` is run.

* `php artisan cashier-inspector:install` now publishes each migration the
  application is missing, instead of skipping the whole step as soon as any
  of them is already there. The old behaviour was correct while the package
  never added a table after a release, but it meant a release that did add
  one could never reach an application installed from an earlier version:
  the command would report the migrations as already published and the new
  table would simply never be created.

## v0.1.3 - 2026-08-18

### Added

* A troubleshooting guide, `TROUBLESHOOTING.md`, covering the recurring
  Cashier and Stripe webhook failures: what each looks like in the dashboard,
  what causes it, and how to fix it. Every section names the finding this
  package records for that condition, so a diagnosis on an event page leads
  straight to the explanation, and the README links to it.

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
