<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectorDiagnostic extends Model
{
    public $timestamps = false;

    protected $table = 'cashier_inspector_diagnostics';

    protected $fillable = [
        'event_id',
        'rule',
        'code',
        'severity',
        'title',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'severity' => Severity::class,
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(InspectorEvent::class, 'event_id');
    }
}
