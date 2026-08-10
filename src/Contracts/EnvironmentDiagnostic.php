<?php

namespace FelicianoPJ\CashierInspector\Contracts;

/**
 * Marks a diagnostic rule whose finding describes the application's own
 * configuration rather than the event it was handed: a missing webhook
 * secret, an incompatible Cashier schema. Such a rule answers the same way
 * for every event, so its diagnostics are recorded on each of them but do
 * not, on their own, make an event a problem - otherwise a single missing
 * environment variable would flag the entire dashboard and bury the events
 * that genuinely went wrong.
 *
 * The finding still appears on the event page and in the copied diagnostic
 * report, at its real severity. Custom rules that check configuration
 * rather than events should implement this too.
 */
interface EnvironmentDiagnostic
{
}
