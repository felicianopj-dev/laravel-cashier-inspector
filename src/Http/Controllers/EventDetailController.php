<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Support\DiagnosticReport;
use Illuminate\Routing\Controller;

class EventDetailController extends Controller
{
    public function __invoke(InspectorEvent $event)
    {
        $deliveries = $event->deliveries()->orderByDesc('received_at')->get();
        $latestDelivery = $deliveries->first();
        $diagnostics = $event->diagnostics()->orderByDesc('created_at')->get();

        return view('cashier-inspector::event', [
            'event' => $event,
            'deliveries' => $deliveries,
            'latestDelivery' => $latestDelivery,
            'diagnostics' => $diagnostics,
            'report' => DiagnosticReport::generate($event, $latestDelivery, $diagnostics),
        ]);
    }
}
