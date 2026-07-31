<?php

namespace FelicianoPJ\CashierInspector\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Parses the dashboard's filter query params once and applies them
 * identically to the dashboard list and the polling endpoint, so the two
 * never drift out of sync with each other.
 */
final class DeliveryFilters
{
    public function __construct(
        public readonly bool $problemsOnly,
        public readonly ?string $severity = null,
        public readonly ?string $status = null,
        public readonly ?string $eventType = null,
        public readonly ?string $mode = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $customerId = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?string $search = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            problemsOnly: ! $request->boolean('all'),
            severity: $request->query('severity') ?: null,
            status: $request->query('status') ?: null,
            eventType: $request->query('event_type') ?: null,
            mode: $request->query('mode') ?: null,
            from: $request->query('from') ?: null,
            to: $request->query('to') ?: null,
            customerId: $request->query('customer_id') ?: null,
            subscriptionId: $request->query('subscription_id') ?: null,
            search: $request->query('search') ?: null,
        );
    }

    public function apply(Builder $query): Builder
    {
        $query->when($this->problemsOnly, fn (Builder $q) => $q->problemsOnly());

        $query->when($this->severity, fn (Builder $q) => $q->where('severity', $this->severity));
        $query->when($this->status, fn (Builder $q) => $q->where('status', $this->status));

        $query->when($this->eventType, fn (Builder $q) => $q->whereHas(
            'event', fn (Builder $q) => $q->where('stripe_event_type', $this->eventType)
        ));

        $query->when($this->mode, fn (Builder $q) => $q->whereHas(
            'event', fn (Builder $q) => $q->where('livemode', $this->mode === 'live')
        ));

        $query->when($this->customerId, fn (Builder $q) => $q->whereHas(
            'event', fn (Builder $q) => $q->where('customer_id', $this->customerId)
        ));

        $query->when($this->subscriptionId, fn (Builder $q) => $q->whereHas(
            'event', fn (Builder $q) => $q->where('subscription_id', $this->subscriptionId)
        ));

        $query->when($this->search, fn (Builder $q) => $q->whereHas(
            'event', fn (Builder $q) => $q->search($this->search)
        ));

        $query->when($this->parseDate($this->from), fn (Builder $q, Carbon $date) => $q->where(
            'received_at', '>=', $date->startOfDay()
        ));

        $query->when($this->parseDate($this->to), fn (Builder $q, Carbon $date) => $q->where(
            'received_at', '<=', $date->endOfDay()
        ));

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return array_filter([
            'all' => $this->problemsOnly ? null : '1',
            'severity' => $this->severity,
            'status' => $this->status,
            'event_type' => $this->eventType,
            'mode' => $this->mode,
            'from' => $this->from,
            'to' => $this->to,
            'customer_id' => $this->customerId,
            'subscription_id' => $this->subscriptionId,
            'search' => $this->search,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
