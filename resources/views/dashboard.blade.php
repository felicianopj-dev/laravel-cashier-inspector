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
        th { text-transform: uppercase; font-size: 0.7rem; color: #666; letter-spacing: 0.03em; }
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
        .pagination { margin-top: 1rem; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="dashboardPolling({
        latestId: {{ $latestId }},
        intervalMs: {{ $pollingIntervalMs }},
        autoRefresh: @js($pollingEnabled),
        problemsOnly: @js($problemsOnly),
        endpoint: '{{ route('cashier-inspector.api.events') }}',
    })">
    <div class="toolbar">
        <h1>Cashier Inspector</h1>
        <div class="controls">
            <a href="{{ request()->fullUrlWithQuery(['all' => $problemsOnly ? '1' : null]) }}">
                {{ $problemsOnly ? 'Show all events' : 'Show problems only' }}
            </a>
            <span>Auto refresh: <button type="button" @click="toggleAutoRefresh()" x-text="autoRefresh ? 'On' : 'Off'"></button></span>
            <span>Last checked: <span x-text="secondsAgoLabel()"></span></span>
            <button type="button" @click="refreshNow()">Refresh</button>
        </div>
    </div>

    <div class="banner" x-show="pendingCount > 0" x-cloak>
        <span x-text="pendingCount"></span>
        <span x-text="pendingCount === 1 ? 'new event' : 'new events'"></span>
        — <button type="button" @click="loadNew()">Load</button>
    </div>

    @if ($deliveries->isEmpty())
        <p class="empty">
            @if ($problemsOnly)
                No problems detected.
            @else
                No webhook deliveries captured yet.
            @endif
        </p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Event Type</th>
                    <th>Event ID</th>
                    <th>Customer</th>
                    <th>Subscription</th>
                    <th>Mode</th>
                    <th>Received</th>
                    <th>Duration</th>
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

        <div class="pagination">
            {{ $deliveries->links() }}
        </div>
    @endif

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('dashboardPolling', (config) => ({
                latestId: config.latestId,
                intervalMs: config.intervalMs,
                autoRefresh: config.autoRefresh,
                problemsOnly: config.problemsOnly,
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
                    const params = new URLSearchParams({ after: this.latestId });

                    if (!this.problemsOnly) {
                        params.set('all', '1');
                    }

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
