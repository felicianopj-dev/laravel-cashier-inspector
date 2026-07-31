<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $problemsOnly = ! $request->boolean('all');

        $deliveries = InspectorDelivery::query()
            ->with('event')
            ->when($problemsOnly, fn ($query) => $query->problemsOnly())
            ->orderByDesc('received_at')
            ->paginate(25)
            ->withQueryString();

        return view('cashier-inspector::dashboard', [
            'deliveries' => $deliveries,
            'problemsOnly' => $problemsOnly,
        ]);
    }
}
