<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectorDelivery extends Model
{
    protected $table = 'cashier_inspector_deliveries';

    protected $fillable = [
        'event_id',
        'status',
        'severity',
        'received_at',
        'handled_at',
        'duration_ms',
        'exception_class',
        'exception_message',
        'exception_trace',
    ];

    protected $casts = [
        'status' => EventStatus::class,
        'severity' => Severity::class,
        'received_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(InspectorEvent::class, 'event_id');
    }
}
