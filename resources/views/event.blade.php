<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->stripe_event_type }} — Cashier Inspector</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; color: #1a1a1a; }
        h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        h2 { font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.03em; color: #666; margin: 2rem 0 0.75rem; }
        a.back { color: #2563eb; text-decoration: none; font-size: 0.875rem; }
        code { font-size: 0.8rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e5e5; font-size: 0.875rem; vertical-align: top; }
        th { text-transform: uppercase; font-size: 0.7rem; color: #666; letter-spacing: 0.03em; }
        .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; }
        .severity-error { background: #fde2e2; color: #9b1c1c; }
        .severity-warning { background: #fef3c7; color: #92400e; }
        .severity-info { background: #dbeafe; color: #1e40af; }
        .severity-success { background: #dcfce7; color: #166534; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.4rem 1.5rem; font-size: 0.875rem; margin: 0; }
        dt { color: #666; }
        dd { margin: 0; }
        .timeline { list-style: none; padding: 0; margin: 0; font-size: 0.875rem; }
        .timeline li { padding: 0.35rem 0; border-left: 2px solid #e5e5e5; padding-left: 1rem; margin-left: 0.25rem; }
        .placeholder { padding: 1rem; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 0.375rem; color: #666; font-size: 0.875rem; }
        .exception { padding: 0.75rem 1rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 0.375rem; margin-top: 0.5rem; }
        .exception pre { white-space: pre-wrap; font-size: 0.75rem; margin: 0.5rem 0 0; }
        pre.payload { background: #0b1021; color: #d1d5db; padding: 1rem; border-radius: 0.375rem; overflow-x: auto; font-size: 0.8rem; }
        summary { cursor: pointer; font-size: 0.875rem; color: #2563eb; }
    </style>
</head>
<body>
    <a class="back" href="{{ route('cashier-inspector.dashboard') }}">&larr; Back to dashboard</a>

    <h1>{{ $event->stripe_event_type }}</h1>
    <p><code>{{ $event->stripe_event_id }}</code> &middot; {{ $event->livemode ? 'Live mode' : 'Test mode' }}</p>

    <h2>Summary</h2>
    <dl>
        <dt>Severity</dt>
        <dd>
            @if ($latestDelivery?->severity)
                <span class="badge severity-{{ $latestDelivery->severity->value }}">{{ ucfirst($latestDelivery->severity->value) }}</span>
            @else
                —
            @endif
        </dd>

        <dt>Processing status</dt>
        <dd>{{ $latestDelivery ? ucfirst($latestDelivery->status->value) : '—' }}</dd>

        <dt>Test/live mode</dt>
        <dd>{{ $event->livemode ? 'Live' : 'Test' }}</dd>

        <dt>Received</dt>
        <dd>{{ $latestDelivery?->received_at?->toDayDateTimeString() ?? '—' }}</dd>

        <dt>Handled</dt>
        <dd>{{ $latestDelivery?->handled_at?->toDayDateTimeString() ?? '—' }}</dd>

        <dt>Duration</dt>
        <dd>{{ $latestDelivery?->duration_ms !== null ? $latestDelivery->duration_ms.' ms' : '—' }}</dd>

        <dt>Customer ID</dt>
        <dd>{{ $event->customer_id ?? '—' }}</dd>

        <dt>Subscription ID</dt>
        <dd>{{ $event->subscription_id ?? '—' }}</dd>
    </dl>

    <h2>Diagnosis</h2>
    <p class="placeholder">Diagnostic rules are not implemented yet. This section will explain what happened and why it matters once the diagnostic engine lands.</p>

    <h2>Suggested checks</h2>
    <p class="placeholder">No suggested checks yet — these will list practical steps once diagnostic rules are available.</p>

    <h2>Processing timeline</h2>
    @if ($latestDelivery)
        <ul class="timeline">
            <li>Event received — {{ $latestDelivery->received_at?->toDayDateTimeString() }}</li>
            @if ($latestDelivery->resolvedAt())
                <li>Event {{ $latestDelivery->status->value }} — {{ $latestDelivery->resolvedAt()->toDayDateTimeString() }}</li>
            @endif
        </ul>
        @if ($deliveries->count() > 1)
            <p class="placeholder">This event was delivered {{ $deliveries->count() }} times — see all attempts below.</p>
        @endif
    @else
        <p class="placeholder">No delivery attempts recorded for this event.</p>
    @endif

    <h2>Delivery attempts ({{ $deliveries->count() }})</h2>
    <table>
        <thead>
            <tr>
                <th>Severity</th>
                <th>Status</th>
                <th>Received</th>
                <th>Duration</th>
                <th>Exception</th>
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
                    <td>{{ $delivery->received_at?->toDayDateTimeString() }}</td>
                    <td>{{ $delivery->duration_ms !== null ? $delivery->duration_ms.' ms' : '—' }}</td>
                    <td>
                        @if ($delivery->exception_class)
                            <strong>{{ $delivery->exception_class }}</strong><br>
                            <small>{{ $delivery->exception_message }}</small>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($latestDelivery?->exception_class)
        <h2>Exception information</h2>
        <div class="exception">
            <strong>{{ $latestDelivery->exception_class }}</strong>
            <p>{{ $latestDelivery->exception_message }}</p>
            @if ($latestDelivery->exception_trace)
                <details>
                    <summary>Stack trace</summary>
                    <pre>{{ $latestDelivery->exception_trace }}</pre>
                </details>
            @endif
        </div>
    @endif

    <h2>Raw payload</h2>
    @if ($event->payload)
        <details>
            <summary>Show payload (redacted)</summary>
            <pre class="payload">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <p class="placeholder">Payload storage is disabled ({{ config('cashier-inspector.storage.store_payloads') ? 'no payload was captured for this event' : 'storage.store_payloads is off' }}).</p>
    @endif
</body>
</html>
