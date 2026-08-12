@extends('cashier-inspector::layout')

@section('title', 'Cashier Inspector')

@section('topbar')
    <div class="controls">
        <a href="{{ request()->fullUrlWithQuery(['all' => $filters->problemsOnly ? '1' : null]) }}">
            {{ $filters->problemsOnly ? 'Show all events' : 'Show problems only' }}
        </a>
    </div>
@endsection

@section('content')
<div x-data="dashboardPolling({
        latestId: {{ $latestId }},
        intervalMs: {{ $pollingIntervalMs }},
        autoRefresh: @js($pollingEnabled),
        filters: @js($filters->queryParams()),
        endpoint: '{{ route('cashier-inspector.api.events') }}',
    })">
    <form class="card card-pad filters" method="GET">
        @if ($filters->problemsOnly)
            <input type="hidden" name="all" value="">
        @else
            <input type="hidden" name="all" value="1">
        @endif

        {{-- Keeps the chosen column ordering when filters are applied. --}}
        @foreach ($sort->queryParams() as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <div class="field">
            <label for="filter-search">Search</label>
            <input type="text" id="filter-search" name="search" value="{{ $filters->search }}" placeholder="evt_, cus_, sub_...">
        </div>

        <div class="field">
            <label for="filter-severity">Severity</label>
            <select id="filter-severity" name="severity">
                <option value="">Any</option>
                @foreach (['info', 'success', 'warning', 'error'] as $value)
                    <option value="{{ $value }}" @selected($filters->severity === $value)>{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="filter-status">Status</label>
            <select id="filter-status" name="status">
                <option value="">Any</option>
                @foreach (['received', 'processing', 'handled', 'failed', 'unmatched'] as $value)
                    <option value="{{ $value }}" @selected($filters->status === $value)>{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="filter-event-type">Event type</label>
            <input type="text" id="filter-event-type" name="event_type" value="{{ $filters->eventType }}" placeholder="customer.subscription.updated">
        </div>

        <div class="field">
            <label for="filter-mode">Mode</label>
            <select id="filter-mode" name="mode">
                <option value="">Any</option>
                <option value="test" @selected($filters->mode === 'test')>Test</option>
                <option value="live" @selected($filters->mode === 'live')>Live</option>
            </select>
        </div>

        <div class="field">
            <label for="filter-customer">Customer ID</label>
            <input type="text" id="filter-customer" name="customer_id" value="{{ $filters->customerId }}" placeholder="cus_...">
        </div>

        <div class="field">
            <label for="filter-subscription">Subscription ID</label>
            <input type="text" id="filter-subscription" name="subscription_id" value="{{ $filters->subscriptionId }}" placeholder="sub_...">
        </div>

        <div class="field row-start">
            <label for="filter-from">From</label>
            <input type="date" id="filter-from" name="from" value="{{ $filters->from }}">
        </div>

        <div class="field">
            <label for="filter-to">To</label>
            <input type="date" id="filter-to" name="to" value="{{ $filters->to }}">
        </div>

        <div class="actions">
            <button type="submit" class="primary">Apply filters</button>
            <a class="clear" href="{{ request()->url() }}">Clear</a>
        </div>
    </form>

    <div class="banner" x-show="pendingCount > 0" x-cloak>
        <span x-text="pendingCount"></span>
        <span x-text="pendingCount === 1 ? 'new event' : 'new events'"></span>
        — <button type="button" class="link" @click="loadNew()">Load</button>
    </div>

    <h2>Webhook deliveries</h2>

    <div class="card">
        <div class="card-pad controls" style="border-bottom: 1px solid var(--border);">
            <span>Auto refresh: <button type="button" class="link" @click="toggleAutoRefresh()" x-text="autoRefresh ? 'On' : 'Off'"></button></span>
            <span>Last checked: <span x-text="secondsAgoLabel()"></span></span>
            <button type="button" class="link" @click="refreshNow()">Refresh</button>
        </div>

        @if ($deliveries->isEmpty())
            <p class="empty">
                @if ($filters->problemsOnly)
                    No problems detected.
                @else
                    No webhook deliveries captured yet.
                @endif
            </p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            @foreach ([
                                'severity' => 'Severity',
                                'status' => 'Status',
                                'event_type' => 'Event Type',
                                'event_id' => 'Event ID',
                                'customer' => 'Customer',
                                'subscription' => 'Subscription',
                                'mode' => 'Mode',
                                'received' => 'Received',
                                'duration' => 'Duration',
                            ] as $column => $label)
                                <th @class(['sorted' => $sort->isActive($column)])>
                                    <a href="{{ request()->url() }}?{{ http_build_query(array_merge($filters->queryParams(), $sort->linkParams($column))) }}">
                                        {{ $label }}
                                        @if ($sort->isActive($column))
                                            <span class="sort-arrow">{{ $sort->direction === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td>
                                    @if ($severity = $delivery->displaySeverity())
                                        <span class="badge severity-{{ $severity->value }}">{{ ucfirst($severity->value) }}</span>
                                    @endif
                                </td>
                                <td>{{ ucfirst($delivery->status->value) }}</td>
                                <td>{{ $delivery->event->stripe_event_type }}</td>
                                <td><a href="{{ route('cashier-inspector.events.show', $delivery->event) }}"><code>{{ $delivery->event->stripe_event_id }}</code></a></td>
                                <td>{{ $delivery->event->customer_id ?? '—' }}</td>
                                <td>{{ $delivery->event->subscription_id ?? '—' }}</td>
                                <td>{{ $delivery->event->livemode ? 'Live' : 'Test' }}</td>
                                <td>{{ $delivery->received_at?->diffForHumans() }}</td>
                                <td>{{ $delivery->duration_ms !== null ? $delivery->duration_ms.' ms' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Laravel's default paginator view is styled for Tailwind, which
                 this dashboard deliberately doesn't ship, so its arrow icons
                 render at their intrinsic size. Plain links instead. --}}
            @if ($deliveries->hasPages())
                <div class="pagination">
                    <span>Showing {{ $deliveries->firstItem() }}-{{ $deliveries->lastItem() }} of {{ $deliveries->total() }}</span>

                    <span class="pages">
                        @if ($deliveries->onFirstPage())
                            <span class="disabled">Previous</span>
                        @else
                            <a href="{{ $deliveries->previousPageUrl() }}" rel="prev">Previous</a>
                        @endif

                        <span>Page {{ $deliveries->currentPage() }} of {{ $deliveries->lastPage() }}</span>

                        @if ($deliveries->hasMorePages())
                            <a href="{{ $deliveries->nextPageUrl() }}" rel="next">Next</a>
                        @else
                            <span class="disabled">Next</span>
                        @endif
                    </span>
                </div>
            @endif
        @endif
    </div>
</div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('dashboardPolling', (config) => ({
                latestId: config.latestId,
                intervalMs: config.intervalMs,
                autoRefresh: config.autoRefresh,
                filters: config.filters,
                endpoint: config.endpoint,
                pendingCount: 0,
                lastCheckedAt: Date.now(),
                secondsAgo: 0,
                timer: null,
                tickTimer: null,

                init() {
                    this.scheduleNext();

                    this.tickTimer = setInterval(() => {
                        this.secondsAgo = Math.floor((Date.now() - this.lastCheckedAt) / 1000);
                    }, 1000);

                    document.addEventListener('visibilitychange', () => {
                        if (document.hidden) {
                            this.clearTimer();
                        } else {
                            this.scheduleNext();
                        }
                    });
                },

                clearTimer() {
                    if (this.timer) {
                        clearTimeout(this.timer);
                        this.timer = null;
                    }
                },

                scheduleNext() {
                    this.clearTimer();

                    if (!this.autoRefresh || document.hidden) {
                        return;
                    }

                    this.timer = setTimeout(() => this.poll(), this.intervalMs);
                },

                toggleAutoRefresh() {
                    this.autoRefresh = !this.autoRefresh;

                    if (this.autoRefresh) {
                        this.poll();
                    } else {
                        this.clearTimer();
                    }
                },

                refreshNow() {
                    this.poll();
                },

                loadNew() {
                    window.location.reload();
                },

                secondsAgoLabel() {
                    return this.secondsAgo <= 1 ? 'just now' : `${this.secondsAgo} seconds ago`;
                },

                async poll() {
                    const params = new URLSearchParams({ after: this.latestId, ...this.filters });

                    try {
                        const response = await fetch(`${this.endpoint}?${params.toString()}`, {
                            headers: { Accept: 'application/json' },
                        });

                        const data = await response.json();

                        this.pendingCount += data.events.length;
                        this.latestId = data.latest_id;
                    } catch (e) {
                        // Silent: a failed poll is simply retried on the next tick.
                    } finally {
                        this.lastCheckedAt = Date.now();
                        this.secondsAgo = 0;
                        this.scheduleNext();
                    }
                },
            }));
        });
    </script>
@endpush
