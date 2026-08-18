<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Enums\Step;
use FelicianoPJ\CashierInspector\Enums\StepStatus;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buffers the phases of one webhook request and writes them in a single
 * insert when the delivery resolves.
 *
 * Bound as a container singleton, so it outlives a single request under a
 * long-running worker. reset() runs from the middleware at the top of every
 * webhook request, which is what keeps a previous request's phases from
 * being attached to this one.
 *
 * ponytail: buffering costs one insert instead of five, at the price of
 * losing the timeline entirely if the process dies without running
 * terminating middleware (OOM, a timeout kill). Normal exceptions still
 * reach the flush. Write each step immediately if that case ever needs
 * covering.
 */
class StepRecorder
{
    /** @var array<string, array<string, mixed>> */
    protected array $open = [];

    /** @var list<array<string, mixed>> */
    protected array $recorded = [];

    public function reset(): void
    {
        $this->open = [];
        $this->recorded = [];
    }

    public function enabled(): bool
    {
        return (bool) config('cashier-inspector.steps.enabled', true);
    }

    public function begin(Step $step, ?Carbon $at = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->open[$step->value] = [
            'step' => $step->value,
            'started_at' => $at ?: Carbon::now(),
        ];
    }

    public function end(Step $step, StepStatus $status = StepStatus::Ok, ?string $message = null, ?Carbon $at = null): void
    {
        if (! $this->enabled() || ! isset($this->open[$step->value])) {
            return;
        }

        $pending = $this->open[$step->value];
        unset($this->open[$step->value]);

        $finishedAt = $at ?: Carbon::now();

        $this->recorded[] = $pending + [
            'status' => $status->value,
            'message' => $message,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $pending['started_at']->diffInMilliseconds($finishedAt),
        ];
    }

    /**
     * Records a phase that is a moment rather than a window, such as the
     * response going back to Stripe.
     */
    public function mark(Step $step, StepStatus $status = StepStatus::Ok, ?string $message = null, ?Carbon $at = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $at = $at ?: Carbon::now();

        $this->recorded[] = [
            'step' => $step->value,
            'status' => $status->value,
            'message' => $message,
            'started_at' => $at,
            'finished_at' => $at,
            'duration_ms' => 0,
        ];
    }

    /**
     * Closes anything still open, which is how a phase that never reported
     * an ending is described: Cashier having no handler for the event type
     * leaves the handler phase open, and it is recorded as skipped.
     */
    public function closeOpen(StepStatus $status, ?string $message = null, ?Carbon $at = null): void
    {
        foreach (array_keys($this->open) as $value) {
            $this->end(Step::from($value), $status, $message, $at);
        }
    }

    /**
     * Summarises a diagnostics run for the timeline. Counted after the
     * phase is closed so the count query isn't measured as part of it.
     */
    public function describeDiagnostics(?int $eventId): string
    {
        $rules = count((array) config('cashier-inspector.diagnostics.rules', []));

        if (! $eventId) {
            return "{$rules} rules ran.";
        }

        try {
            $findings = InspectorDiagnostic::where('event_id', $eventId)->count();
        } catch (Throwable $e) {
            return "{$rules} rules ran.";
        }

        return "{$rules} rules ran, ".($findings === 1 ? '1 finding' : "{$findings} findings").' recorded.';
    }

    public function flush(int $deliveryId): void
    {
        if (! $this->enabled() || $this->recorded === []) {
            return;
        }

        $now = Carbon::now();

        $rows = array_map(fn (array $row) => $row + [
            'delivery_id' => $deliveryId,
            'created_at' => $now,
        ], $this->recorded);

        usort($rows, fn (array $a, array $b) => $a['started_at'] <=> $b['started_at']);

        $this->recorded = [];

        try {
            InspectorStep::insert($rows);
        } catch (Throwable $e) {
            // Same contract as every other capture path: a timeline that
            // cannot be written must not be what breaks the webhook request.
            Log::warning('Cashier Inspector failed to record a webhook timeline.', [
                'exception' => $e,
            ]);
        }
    }
}
