<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class InspectorDelivery extends Model
{
    protected $table = 'cashier_inspector_deliveries';

    /**
     * How long a delivery can sit in "received" before it counts as a
     * problem, on the assumption that Cashier's webhook controller
     * normally resolves synchronously within a single request.
     */
    protected const STUCK_AFTER_SECONDS = 60;

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

    public function scopeProblemsOnly(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereIn('severity', [Severity::Warning->value, Severity::Error->value])
                ->orWhere('status', EventStatus::Unmatched->value)
                ->orWhere(function (Builder $query) {
                    $query->where('status', EventStatus::Received->value)
                        ->where('received_at', '<', Carbon::now()->subSeconds(self::STUCK_AFTER_SECONDS));
                });
        });
    }

    /**
     * When this delivery was resolved (handled, failed, or unmatched).
     * handled_at only gets set on the success path; failed/unmatched
     * deliveries only store a duration, so it's derived from that instead.
     */
    public function resolvedAt(): ?Carbon
    {
        if ($this->handled_at) {
            return $this->handled_at;
        }

        if ($this->duration_ms !== null && $this->received_at) {
            return $this->received_at->clone()->addMilliseconds($this->duration_ms);
        }

        return null;
    }
}
