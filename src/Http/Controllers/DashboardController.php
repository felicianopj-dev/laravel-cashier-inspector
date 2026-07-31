<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * How long a delivery can sit in "received" before it counts as a
     * problem in the default view, on the assumption that Cashier's
     * webhook controller normally resolves synchronously within a
     * single request.
     */
    protected const STUCK_AFTER_SECONDS = 60;

    public function index(Request $request)
    {
        $problemsOnly = ! $request->boolean('all');

        $deliveries = InspectorDelivery::query()
            ->with('event')
            ->when($problemsOnly, function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('severity', [Severity::Warning->value, Severity::Error->value])
                        ->orWhere('status', EventStatus::Unmatched->value)
                        ->orWhere(function ($query) {
                            $query->where('status', EventStatus::Received->value)
                                ->where('received_at', '<', Carbon::now()->subSeconds(self::STUCK_AFTER_SECONDS));
                        });
                });
            })
            ->orderByDesc('received_at')
            ->paginate(25)
            ->withQueryString();

        return view('cashier-inspector::dashboard', [
            'deliveries' => $deliveries,
            'problemsOnly' => $problemsOnly,
        ]);
    }
}
