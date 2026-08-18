<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectorStep extends Model
{
    public $timestamps = false;

    protected $table = 'cashier_inspector_steps';

    protected $fillable = [
        'delivery_id',
        'step',
        'status',
        'message',
        'started_at',
        'finished_at',
        'duration_ms',
        'created_at',
    ];

    protected $casts = [
        'step' => Step::class,
        'status' => StepStatus::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(InspectorDelivery::class, 'delivery_id');
    }
}
