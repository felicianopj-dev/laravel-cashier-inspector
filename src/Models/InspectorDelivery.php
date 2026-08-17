<?php

namespace FelicianoPJ\CashierInspector\Models;

use FelicianoPJ\CashierInspector\Contracts\EnvironmentDiagnostic;
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

    /**
     * Severity as the dashboard shows it: the worst of this attempt's own
     * outcome and the findings on its event.
     *
     * The row's own severity is about processing alone - Cashier handling an
     * event without complaint records a success - so an event whose only
     * problem is a duplicate delivery or a missing local subscription would
     * read as healthy in the list even though the default view deliberately
     * keeps it. Environment findings are excluded for the same reason
     * scopeProblemsOnly() excludes them: they describe the installation and
     * are identical on every event.
     *
     * Sorting and filtering go through severityRankSql() so the column
     * orders and filters by the value it renders.
     */
    public function displaySeverity(): ?Severity
    {
        return $this->event?->diagnostics
            ->whereNotIn('rule', self::environmentRuleClasses())
            ->pluck('severity')
            ->push($this->severity)
            ->filter()
            ->sortByDesc(fn (Severity $severity) => $severity->rank())
            ->first() ?? $this->severity;
    }

    /**
     * displaySeverity() expressed in SQL, as a rank rather than a name so it
     * can be compared and ordered. Returned with its bindings because a
     * Laravel expression cannot carry any.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function severityRankSql(): array
    {
        $deliveries = (new self)->getTable();
        $diagnostics = (new InspectorDiagnostic)->getTable();
        $environment = self::environmentRuleClasses();

        $own = self::severityRankCase($deliveries.'.severity');

        $excluded = $environment === []
            ? ''
            : ' and d.rule not in ('.implode(', ', array_fill(0, count($environment), '?')).')';

        $worstFinding = 'coalesce((select max('.self::severityRankCase('d.severity').')'
            ." from {$diagnostics} as d where d.event_id = {$deliveries}.event_id{$excluded}), 0)";

        return [
            "case when {$worstFinding} > {$own} then {$worstFinding} else {$own} end",
            [...$environment, ...$environment],
        ];
    }

    /**
     * Values come from the Severity enum itself, never from a request.
     */
    protected static function severityRankCase(string $column): string
    {
        $cases = '';

        foreach (Severity::cases() as $severity) {
            $cases .= " when '{$severity->value}' then {$severity->rank()}";
        }

        return "case {$column}{$cases} else 0 end";
    }

    /**
     * A delivery counts as a problem when it failed on its own terms, when
     * Cashier had no handler for it, when it never resolved, or when a
     * diagnostic rule flagged the event it belongs to.
     *
     * That last case is why the diagnostics are consulted rather than just
     * this row's own severity: a delivery Cashier handled successfully is
     * recorded as a success, so an event whose only problem is a duplicate
     * delivery or a missing local subscription would otherwise be filtered
     * out of the default view - which is exactly the kind of problem this
     * package exists to surface.
     *
     * Diagnostics from EnvironmentDiagnostic rules are excluded, since they
     * describe the installation rather than the event and would otherwise
     * mark every event ever received as a problem.
     */
    public function scopeProblemsOnly(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereIn('severity', [Severity::Warning->value, Severity::Error->value])
                ->orWhere('status', EventStatus::Unmatched->value)
                ->orWhere(function (Builder $query) {
                    $query->where('status', EventStatus::Received->value)
                        ->where('received_at', '<', Carbon::now()->subSeconds(self::STUCK_AFTER_SECONDS));
                })
                ->orWhereHas('event.diagnostics', function (Builder $query) {
                    $query->whereIn('severity', [Severity::Warning->value, Severity::Error->value])
                        ->whereNotIn('rule', self::environmentRuleClasses());
                });
        });
    }

    /**
     * Configured rules whose findings describe the application's own
     * configuration rather than the event, so their diagnostics never make
     * an event a problem on their own. Read from config rather than
     * hardcoded, so a custom rule can opt in the same way.
     *
     * Public because the health check reports these conditions directly and
     * has to leave their findings out for the same reason this scope does.
     *
     * @return array<int, class-string>
     */
    public static function environmentRuleClasses(): array
    {
        return array_values(array_filter(
            (array) config('cashier-inspector.diagnostics.rules', []),
            fn ($rule) => is_string($rule) && is_subclass_of($rule, EnvironmentDiagnostic::class)
        ));
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
