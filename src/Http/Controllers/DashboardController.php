<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\DeliveryFilters;
use FelicianoPJ\CashierInspector\Support\DeliverySort;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    /**
     * Floor for the configurable polling interval, so a misconfigured
     * value can't hammer the server.
     */
    protected const MIN_POLLING_INTERVAL_MS = 2000;

    public function index(Request $request)
    {
        $filters = DeliveryFilters::fromRequest($request);
        $sort = DeliverySort::fromRequest($request);

        $query = $filters->apply(
            InspectorDelivery::query()->with('event')
        );

        $deliveries = $sort->apply(clone $query)
            ->paginate(25)
            ->withQueryString();

        return view('cashier-inspector::dashboard', [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'sort' => $sort,
            'latestId' => (clone $query)->max('id') ?? 0,
            'pollingEnabled' => (bool) config('cashier-inspector.polling.enabled'),
            'pollingIntervalMs' => max(
                (int) config('cashier-inspector.polling.interval_ms'),
                self::MIN_POLLING_INTERVAL_MS
            ),
        ]);
    }
}
