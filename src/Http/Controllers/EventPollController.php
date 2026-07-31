<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Support\DeliveryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Backs the dashboard's polling loop: deliveries newer than the last known
 * id, filtered identically to the currently displayed table.
 */
class EventPollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $after = (int) $request->integer('after');
        $filters = DeliveryFilters::fromRequest($request);

        $query = $filters->apply(
            InspectorDelivery::query()->with('event')
        );

        $deliveries = (clone $query)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->get();

        $latestId = (clone $query)->max('id') ?? $after;

        return response()->json([
            'events' => $deliveries->map($this->toPayload(...))->all(),
            'latest_id' => $latestId,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }

    protected function toPayload(InspectorDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'status' => $delivery->status->value,
            'severity' => $delivery->severity?->value,
            'stripe_event_id' => $delivery->event->stripe_event_id,
            'stripe_event_type' => $delivery->event->stripe_event_type,
            'customer_id' => $delivery->event->customer_id,
            'subscription_id' => $delivery->event->subscription_id,
            'livemode' => $delivery->event->livemode,
            'received_at' => $delivery->received_at?->toIso8601String(),
            'duration_ms' => $delivery->duration_ms,
        ];
    }
}
