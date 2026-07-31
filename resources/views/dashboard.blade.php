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
        .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; }
        .severity-error { background: #fde2e2; color: #9b1c1c; }
        .severity-warning { background: #fef3c7; color: #92400e; }
        .severity-info { background: #dbeafe; color: #1e40af; }
        .severity-success { background: #dcfce7; color: #166534; }
        .empty { padding: 2rem 0; color: #666; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; }
        .toolbar a { color: #2563eb; text-decoration: none; font-size: 0.875rem; }
        .pagination { margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="toolbar">
        <h1>Cashier Inspector</h1>
        <a href="{{ request()->fullUrlWithQuery(['all' => $problemsOnly ? '1' : null]) }}">
            {{ $problemsOnly ? 'Show all events' : 'Show problems only' }}
        </a>
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
                        <td><code>{{ $delivery->event->stripe_event_id }}</code></td>
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
</body>
</html>
