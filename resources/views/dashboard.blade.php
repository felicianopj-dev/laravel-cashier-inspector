<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Inspector</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; color: #1a1a1a; }
        h1 { font-size: 1.25rem; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e5e5; font-size: 0.875rem; }
        th { text-transform: uppercase; font-size: 0.7rem; color: #666; letter-spacing: 0.03em; white-space: nowrap; }
        th a { color: inherit; text-decoration: none; }
        th a:hover { color: #2563eb; }
        th.sorted a { color: #1a1a1a; }
        .sort-arrow { color: #2563eb; font-size: 0.65rem; }
        code { font-size: 0.8rem; }
        td a { color: #2563eb; text-decoration: none; }
        td a:hover { text-decoration: underline; }
        .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; }
        .severity-error { background: #fde2e2; color: #9b1c1c; }
        .severity-warning { background: #fef3c7; color: #92400e; }
        .severity-info { background: #dbeafe; color: #1e40af; }
        .severity-success { background: #dcfce7; color: #166534; }
        .empty { padding: 2rem 0; color: #666; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .toolbar a { color: #2563eb; text-decoration: none; font-size: 0.875rem; }
        .controls { display: flex; align-items: center; gap: 1rem; font-size: 0.8125rem; color: #444; }
        .controls button { font: inherit; color: #2563eb; background: none; border: none; cursor: pointer; padding: 0; }
        .banner { margin-top: 1rem; padding: 0.6rem 1rem; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.375rem; font-size: 0.875rem; color: #1e40af; }
        .banner button { font: inherit; font-weight: 600; color: #1e40af; background: none; border: none; cursor: pointer; padding: 0; text-decoration: underline; }
        .filters { display: flex; flex-wrap: wrap; align-items: end; gap: 0.75rem; margin-top: 1.25rem; padding: 0.75rem 1rem; background: #f9fafb; border: 1px solid #e5e5e5; border-radius: 0.375rem; }
        .filters .field { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.75rem; color: #666; }
        .filters input, .filters select { font-size: 0.8125rem; padding: 0.3rem 0.4rem; border: 1px solid #d1d5db; border-radius: 0.25rem; }
        .filters .actions { display: flex; gap: 0.75rem; align-items: center; }
        .filters button { font: inherit; padding: 0.35rem 0.75rem; background: #2563eb; color: #fff; border: none; border-radius: 0.25rem; cursor: pointer; font-size: 0.8125rem; }
        .filters .clear { color: #666; font-size: 0.8125rem; text-decoration: none; }
        .pagination { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; font-size: 0.8125rem; color: #666; }
        .pagination .pages { display: flex; align-items: center; gap: 0.75rem; }
        .pagination a { color: #2563eb; text-decoration: none; }
        .pagination a:hover { text-decoration: underline; }
        .pagination .disabled { color: #b0b0b0; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="dashboardPolling({
        latestId: {{ $latestId }},
        intervalMs: {{ $pollingIntervalMs }},
        autoRefresh: @js($pollingEnabled),
        filters: @js($filters->queryParams()),
        endpoint: '{{ route('cashier-inspector.api.events') }}',
    })">
    <div class="toolbar">
        <h1>Cashier Inspector</h1>
        <div class="controls">
            <a href="{{ request()->fullUrlWithQuery(['all' => $filters->problemsOnly ? '1' : null]) }}">
                {{ $filters->problemsOnly ? 'Show all events' : 'Show problems only' }}
            </a>
            <span>Auto refresh: <button type="button" @click="toggleAutoRefresh()" x-text="autoRefresh ? 'On' : 'Off'"></button></span>
            <span>Last checked: <span x-text="secondsAgoLabel()"></span></span>
            <button type="button" @click="refreshNow()">Refresh</button>
        </div>
    </div>

    <form class="filters" method="GET">
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

        <div class="field">
            <label for="filter-from">From</label>
            <input type="date" id="filter-from" name="from" value="{{ $filters->from }}">
        </div>

        <div class="field">
            <label for="filter-to">To</label>
            <input type="date" id="filter-to" name="to" value="{{ $filters->to }}">
        </div>

        <div class="actions">
            <button type="submit">Apply filters</button>
            <a class="clear" href="{{ request()->url() }}">Clear</a>
        </div>
    </form>

    <div class="banner" x-show="pendingCount > 0" x-cloak>
        <span x-text="pendingCount"></span>
        <span x-text="pendingCount === 1 ? 'new event' : 'new events'"></span>
        — <button type="button" @click="loadNew()">Load</button>
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
                            @if ($delivery->severity)
                                <span class="badge severity-{{ $delivery->severity->value }}">{{ ucfirst($delivery->severity->value) }}</span>
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
    <script defer src="{{ route('cashier-inspector.assets.alpine') }}"></script>
</body>
</html>
