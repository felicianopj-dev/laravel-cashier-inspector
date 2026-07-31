<?php

namespace FelicianoPJ\CashierInspector\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectorEvent extends Model
{
    protected $table = 'cashier_inspector_events';

    protected $fillable = [
        'stripe_event_id',
        'stripe_event_type',
        'stripe_api_version',
        'livemode',
        'payload',
        'customer_id',
        'subscription_id',
        'invoice_id',
        'checkout_session_id',
        'billable_type',
        'billable_id',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'payload' => 'array',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(InspectorDelivery::class, 'event_id');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(InspectorDiagnostic::class, 'event_id');
    }

    public function getRouteKeyName(): string
    {
        return 'stripe_event_id';
    }

    /**
     * Matches the identifiers a developer would typically paste in
     * (evt_/cus_/sub_/invoice/checkout id). Billable model id and email
     * search are not implemented: billable_id/billable_type are never
     * populated yet (Cashier::findBillable() resolution isn't wired up),
     * and email isn't extracted into a queryable column at all.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term) {
            $query->where('stripe_event_id', 'like', "%{$term}%")
                ->orWhere('customer_id', 'like', "%{$term}%")
                ->orWhere('subscription_id', 'like', "%{$term}%")
                ->orWhere('invoice_id', 'like', "%{$term}%")
                ->orWhere('checkout_session_id', 'like', "%{$term}%");
        });
    }
}
