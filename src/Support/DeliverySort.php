<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Column ordering for the dashboard list. Kept separate from
 * DeliveryFilters because the polling endpoint shares the filters but
 * orders by id on purpose - it asks for what is new, not for what the
 * table currently shows.
 *
 * Some columns live on the delivery and some on its event. The event ones
 * are ordered through a correlated subquery rather than a join, so the
 * existing eager loading and the select list stay untouched.
 */
final class DeliverySort
{
    /**
     * Sortable columns, keyed by the value that appears in the URL.
     * Anything not listed here is ignored, so the query param can never
     * reach the database as a column name.
     *
     * @var array<string, array{0: 'delivery'|'event', 1: string}>
     */
    public const COLUMNS = [
        'severity' => ['delivery', 'severity'],
        'status' => ['delivery', 'status'],
        'event_type' => ['event', 'stripe_event_type'],
        'event_id' => ['event', 'stripe_event_id'],
        'customer' => ['event', 'customer_id'],
        'subscription' => ['event', 'subscription_id'],
        'mode' => ['event', 'livemode'],
        'received' => ['delivery', 'received_at'],
        'duration' => ['delivery', 'duration_ms'],
    ];

    public function __construct(
        public readonly ?string $column = null,
        public readonly string $direction = 'asc',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $column = $request->query('sort');

        return new self(
            column: is_string($column) && isset(self::COLUMNS[$column]) ? $column : null,
            direction: $request->query('direction') === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * Newest first when no column was chosen, which is what the dashboard
     * has always defaulted to. Every ordering ends on the delivery id so
     * rows with equal values keep a stable position across pages.
     */
    public function apply(Builder $query): Builder
    {
        if ($this->column === null) {
            return $query->orderByDesc('received_at')->orderByDesc('id');
        }

        [$source, $name] = self::COLUMNS[$this->column];

        // The severity column renders the worst of the delivery's own
        // outcome and its event's findings, so it has to order by that
        // rather than by the stored column.
        if ($this->column === 'severity') {
            [$sql, $bindings] = InspectorDelivery::severityRankSql();

            $query->orderByRaw("{$sql} {$this->direction}", $bindings);
        } elseif ($source === 'delivery') {
            $query->orderBy($name, $this->direction);
        } else {
            $query->orderBy($this->eventColumn($name), $this->direction);
        }

        return $query->orderByDesc('id');
    }

    public function isActive(string $column): bool
    {
        return $this->column === $column;
    }

    /**
     * Query params for this column's header link: ascending the first
     * time, then flipping each time the active column is clicked again.
     *
     * @return array<string, string>
     */
    public function linkParams(string $column): array
    {
        return [
            'sort' => $column,
            'direction' => $this->isActive($column) && $this->direction === 'asc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Params that keep the current ordering when something else on the
     * page rebuilds the query string, such as the filter form.
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        if ($this->column === null) {
            return [];
        }

        return ['sort' => $this->column, 'direction' => $this->direction];
    }

    protected function eventColumn(string $name): Builder
    {
        return InspectorEvent::query()
            ->select($name)
            ->whereColumn(
                (new InspectorEvent)->getTable().'.id',
                (new InspectorDelivery)->getTable().'.event_id'
            );
    }
}
