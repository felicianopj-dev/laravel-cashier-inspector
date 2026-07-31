<?php

namespace FelicianoPJ\CashierInspector\Models;

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
}
