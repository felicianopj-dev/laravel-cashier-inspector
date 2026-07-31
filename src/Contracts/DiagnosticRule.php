<?php

namespace FelicianoPJ\CashierInspector\Contracts;

use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

interface DiagnosticRule
{
    /**
     * Whether this rule applies to the given event at all (e.g. its
     * Stripe event type). Rules that don't apply are never diagnosed.
     */
    public function supports(InspectorEvent $event): bool;

    public function diagnose(InspectorEvent $event): DiagnosticResult;
}
