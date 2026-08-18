# Common Cashier webhook failures

What goes wrong with Laravel Cashier and Stripe webhooks, what each failure
looks like in Cashier Inspector, and how to fix it.

Every heading below names the finding Cashier Inspector records, where there
is one. Run `php artisan cashier-inspector:check` first — several of these
are reported directly by it, before you go looking at individual events.

## Nothing arrives at all

The dashboard is empty and `cashier-inspector:check` reports no webhook
events received.

Cashier registers its own route at `POST /stripe/webhook`, or
`{CASHIER_PATH}/webhook` if you changed `CASHIER_PATH`. Nothing captures
events until Stripe is actually sending them there.

* Confirm the endpoint exists in Stripe and its URL matches that route.
  Cashier can create it for you with `php artisan cashier:webhook`, which
  registers the event types Cashier itself handles.
* Confirm the endpoint is enabled for the events you care about. An endpoint
  subscribed to the wrong events delivers nothing without erroring.
* Locally, Stripe cannot reach your machine. Forward with the Stripe CLI
  (`stripe listen --forward-to localhost:8000/stripe/webhook`) and use the
  signing secret that command prints, which is not the same as the one in
  the Stripe dashboard.
* If your application is behind authentication middleware applied globally,
  make sure it does not cover Cashier's route. Stripe cannot log in.

## Stripe reports failures, the dashboard stays empty

Stripe's endpoint page shows delivery attempts failing with a 4xx while
Cashier Inspector never records the event.

This is signature verification rejecting the request before Cashier's own
events fire, which is also why the event never reaches this package.

* `STRIPE_WEBHOOK_SECRET` must be the signing secret **of that specific
  endpoint**. Every endpoint has its own, and test and live endpoints never
  share one.
* Cashier rejects requests whose timestamp drifts more than
  `STRIPE_WEBHOOK_TOLERANCE` seconds from now, 300 by default. A machine
  with a badly wrong clock fails every delivery.
* Any middleware that modifies the request body invalidates the signature.
  The signature is computed over the exact bytes Stripe sent.

### `webhook_secret_missing`

The opposite problem: no secret is set at all, so nothing is verified and
anyone who finds the URL can post events to it. Set
`STRIPE_WEBHOOK_SECRET`. Cashier Inspector records this against every event
because it describes the installation rather than any one delivery.

## Events are handled, but nothing changes locally

### `missing_billable_model`

The single most common Cashier failure, and the one hardest to see without
this package.

Cashier resolves the local customer by matching the event's Stripe customer
id against the `stripe_id` column on your billable model. When no row
matches, the handler does nothing at all — and still answers Stripe with
200. Stripe shows a successful delivery, Laravel logs nothing, and the
subscription silently never appears.

* The customer was created directly in the Stripe dashboard, so no local
  model was ever linked to it.
* The customer was created through the API by something other than Cashier,
  and `stripe_id` was never written back.
* You are pointing at a Stripe account whose customers belong to a different
  database, which happens when a production endpoint is left pointed at a
  staging application.

Fix by making sure the local model carries the Stripe customer id, then
replaying the event from Stripe's dashboard.

### `missing_local_subscription`

The event names a subscription Cashier has no `subscriptions` row for.

Note that starting a subscription in the Stripe dashboard rather than
through `newSubscription()` is not itself the problem: Cashier writes a
local row from `customer.subscription.created`, giving it the type
`default`. What it cannot do is write that row for a customer it cannot
resolve, which brings you back to the failure above.

So the usual causes are that the creation event was never received — the
endpoint was added after the subscription existed — or that it was received
and matched nothing.

## Cashier ignores the event

### `webhook_unmatched`

Cashier handles a deliberately small set of event types — subscription
created, updated and deleted, customer updated and deleted, payment method
automatically updated, and two invoice events. Anything else reaches the
controller and is answered 200 without doing anything.

This is normal for most event types and is recorded as information, not a
problem. It matters when you expected the event to do something: if you need
behaviour on `invoice.payment_failed`, for example, nothing in Cashier
provides it. Listen for Cashier's `WebhookReceived` event in your own
application, or extend Cashier's webhook controller.

## The same event arrives more than once

### `duplicate_delivery`

Stripe retries deliveries it did not get a timely success from, and will
also redeliver on request. Receiving an event twice is expected behaviour,
not a bug in your application.

It becomes a real problem when your own handlers are not idempotent — a
listener that grants credit or sends mail on every delivery will do it
twice. Key that work on the Stripe event id, which is unique per event and
is what Cashier Inspector stores as `stripe_event_id`.

Frequent duplicates usually mean your endpoint is answering too slowly. See
below.

## Processing is slow

### `slow_processing`

Cashier's webhook route runs your listeners synchronously before answering
Stripe. Work that belongs in a queue — sending mail, calling other APIs,
generating documents — delays that answer, and a slow enough endpoint gets
retried, which shows up as duplicate deliveries.

Move that work into queued jobs. The threshold this rule reports on is
`CASHIER_INSPECTOR_SLOW_PROCESSING_THRESHOLD_MS`, 5000 by default.

Note that Cashier Inspector's own diagnostic rules run in this same request.
The two rules that compare local state against a live Stripe API call are
off by default for exactly this reason.

## The handler threw

### `processing_exception`

Something failed while Cashier was handling the event. Stripe sees a failed
delivery and will retry it, so a bug here turns into repeated failures
rather than one.

The event page names the exception class and message. Stack traces are not
stored unless you turn on
`CASHIER_INSPECTOR_STORE_EXCEPTION_TRACES`, since they can carry
application data.

## Test and live are crossed

### `mode_mismatch`

The event was sent in one mode and the application is configured with the
other mode's secret key. Almost always a leftover endpoint: a test endpoint
still pointed at an application that has been switched to live keys, or the
reverse.

Local Cashier data and live Stripe data crossing is worth fixing
immediately — the subscription rows written from the wrong mode refer to
objects that do not exist in the account you are actually billing.

## Cashier's own tables are wrong

### `cashier_schema_incompatible`

Cashier publishes its migrations rather than loading them, so `migrate`
alone never creates its tables. If they are missing or out of date:

```bash
php artisan vendor:publish --tag=cashier-migrations
php artisan migrate
```

This also appears after a Cashier major upgrade that added columns, where
the published migration was never re-run.

## Local state has drifted from Stripe

### `subscription_status_mismatch`, `subscription_price_mismatch`

The local subscription row disagrees with what Stripe currently holds —
a different status, or a different set of prices. Usually the tail of one of
the failures above: an event that was never applied leaves the local row
frozen at its last known state.

These two rules are the only ones that call the Stripe API, and they are off
by default. Turn them on with `CASHIER_INSPECTOR_STRIPE_API_CHECKS=true`,
bearing in mind they add a network call to webhook processing.

### `duplicate_subscription_type`

One billable model holds two subscriptions that are both valid and share the
same Cashier type, usually `default`. Since `$user->subscription('default')`
returns one of them, the application is now making decisions from an
arbitrary choice between two live subscriptions.

Typically a customer who resubscribed while an old subscription was still
active. Cancel the one that should not be running, in Stripe.

## Nothing here matches

Open the event in the dashboard and use "Copy diagnostic report", or run:

```bash
php artisan cashier-inspector:event evt_123
```

The report carries the event type, mode, the versions in play, every finding
and its suggested checks — and never the raw payload — so it is safe to
paste into an issue or a support thread.
