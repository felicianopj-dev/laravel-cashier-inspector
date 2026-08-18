@extends('cashier-inspector::layout')

@section('title', $event->stripe_event_type.' — Cashier Inspector')

@section('topbar')
    <div class="controls">
        <a href="{{ route('cashier-inspector.dashboard') }}">&larr; Back to dashboard</a>
    </div>
@endsection

@section('content')
    <h1>{{ $event->stripe_event_type }}</h1>
    <p class="controls">
        <code>{{ $event->stripe_event_id }}</code>
        <span>{{ $event->livemode ? 'Live mode' : 'Test mode' }}</span>
        <span x-data="{ copied: false, report: @js($report) }">
            <button
                type="button"
                @click="navigator.clipboard.writeText(report); copied = true; setTimeout(() => copied = false, 2000)"
            >
                <span x-show="!copied">Copy diagnostic report</span>
                <span x-show="copied" x-cloak>Copied!</span>
            </button>
        </span>
        @if ($telescopeUrl)
            <a href="{{ $telescopeUrl }}">View in Telescope</a>
        @endif
    </p>

    <h2>Summary</h2>
    <dl class="card card-pad">
        <div>
            <dt>Severity</dt>
            <dd>
                @if ($severity = $latestDelivery?->displaySeverity())
                    <span class="badge severity-{{ $severity->value }}">{{ ucfirst($severity->value) }}</span>
                @else
                    —
                @endif
            </dd>
        </div>

        <div>
            <dt>Processing status</dt>
            <dd>{{ $latestDelivery ? ucfirst($latestDelivery->status->value) : '—' }}</dd>
        </div>

        <div>
            <dt>Test/live mode</dt>
            <dd>{{ $event->livemode ? 'Live' : 'Test' }}</dd>
        </div>

        <div>
            <dt>Received</dt>
            <dd>{{ $latestDelivery?->received_at?->toDayDateTimeString() ?? '—' }}</dd>
        </div>

        <div>
            <dt>Handled</dt>
            <dd>{{ $latestDelivery?->handled_at?->toDayDateTimeString() ?? '—' }}</dd>
        </div>

        <div>
            <dt>Duration</dt>
            <dd>{{ $latestDelivery?->duration_ms !== null ? $latestDelivery->duration_ms.' ms' : '—' }}</dd>
        </div>

        <div>
            <dt>Customer ID</dt>
            <dd>{{ $event->customer_id ?? '—' }}</dd>
        </div>

        <div>
            <dt>Subscription ID</dt>
            <dd>{{ $event->subscription_id ?? '—' }}</dd>
        </div>
    </dl>

    <h2>Diagnosis</h2>
    @if ($diagnostics->isEmpty())
        <p class="placeholder">No diagnostic rules are registered yet — this package doesn't ship any built in. Register your own via the `cashier-inspector.diagnostics.rules` config to see findings here.</p>
    @else
        @foreach ($diagnostics as $diagnostic)
            <div class="finding severity-{{ $diagnostic->severity->value }}">
                <span class="badge severity-{{ $diagnostic->severity->value }}">{{ ucfirst($diagnostic->severity->value) }}</span>
                <strong>{{ $diagnostic->title }}</strong>
                <p>{{ $diagnostic->message }}</p>
            </div>
        @endforeach
    @endif

    <h2>Suggested checks</h2>
    @php $suggestedChecks = $diagnostics->flatMap(fn ($diagnostic) => $diagnostic->context['suggested_checks'] ?? [])->unique(); @endphp
    @if ($suggestedChecks->isEmpty())
        <p class="placeholder">No suggested checks yet — these will list practical steps once a triggered diagnostic rule provides them.</p>
    @else
        <ul class="card card-pad">
            @foreach ($suggestedChecks as $check)
                <li>{{ $check }}</li>
            @endforeach
        </ul>
    @endif

    <h2>Diagnostic rules</h2>
    @if ($diagnostics->isEmpty())
        <p class="placeholder">No diagnostic rules have triggered for this event.</p>
    @else
        <div class="card table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Severity</th>
                        <th>Rule</th>
                        <th>Context</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($diagnostics as $diagnostic)
                        <tr>
                            <td><code>{{ $diagnostic->code }}</code></td>
                            <td><span class="badge severity-{{ $diagnostic->severity->value }}">{{ ucfirst($diagnostic->severity->value) }}</span></td>
                            <td><code>{{ class_basename($diagnostic->rule) }}</code></td>
                            <td class="context">
                                @if ($diagnostic->context)
                                    <pre class="payload">{{ json_encode($diagnostic->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Processing timeline</h2>
    @if ($latestDelivery)
        @if ($latestDelivery->steps->isNotEmpty())
            <ul class="timeline card card-pad">
                @foreach ($latestDelivery->steps as $step)
                    <li>
                        <span class="badge severity-{{ $step->status->value === 'failed' ? 'error' : ($step->status->value === 'skipped' ? 'info' : 'success') }}">{{ $step->status->value }}</span>
                        <strong title="{{ $step->step->description() }}">{{ $step->step->label() }}</strong>
                        — {{ $step->started_at?->format('H:i:s.v') }}
                        @if ($step->duration_ms)
                            <span class="placeholder">({{ $step->duration_ms }} ms)</span>
                        @endif
                        @if ($step->message)
                            <div class="placeholder">{{ $step->message }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            {{-- Deliveries recorded before timelines existed, or with step
                 recording turned off, still get the received/resolved pair. --}}
            <ul class="timeline card card-pad">
                <li>Event received — {{ $latestDelivery->received_at?->toDayDateTimeString() }}</li>
                @if ($latestDelivery->resolvedAt())
                    <li>Event {{ $latestDelivery->status->value }} — {{ $latestDelivery->resolvedAt()->toDayDateTimeString() }}</li>
                @endif
            </ul>
        @endif
        @if ($deliveries->count() > 1)
            <p class="placeholder" style="margin-top: 0.5rem;">This event was delivered {{ $deliveries->count() }} times — see all attempts below.</p>
        @endif
    @else
        <p class="placeholder">No delivery attempts recorded for this event.</p>
    @endif

    <h2>Delivery attempts ({{ $deliveries->count() }})</h2>
    <div class="card table-wrap">
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
                        <td class="wrap-cell">
                            @if ($delivery->exception_class)
                                <strong>{{ $delivery->exception_class }}</strong><br>
                                <small class="muted">{{ $delivery->exception_message }}</small>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

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
        <details class="card card-pad">
            <summary>Show payload (redacted)</summary>
            <pre class="payload">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <p class="placeholder">Payload storage is disabled ({{ config('cashier-inspector.storage.store_payloads') ? 'no payload was captured for this event' : 'storage.store_payloads is off' }}).</p>
    @endif
@endsection
