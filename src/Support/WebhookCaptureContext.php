<?php

namespace FelicianoPJ\CashierInspector\Support;

/**
 * Request-scoped holder for the webhook currently being processed by
 * Cashier's controller, bridging the WebhookReceived and WebhookHandled
 * listeners so the handled side knows when its matching event was received.
 */
class WebhookCaptureContext
{
    protected ?WebhookCapture $current = null;

    protected ?WebhookFailure $failure = null;

    protected ?int $terminatedStatus = null;

    /**
     * Accepts null so the receiving listener can start every webhook
     * request unconditionally, including ones it couldn't capture. This
     * container binding is a singleton, so in a long-running worker that
     * null is what stops a previous request's capture from lingering.
     */
    public function start(?WebhookCapture $capture): void
    {
        $this->current = $capture;
    }

    public function finish(): void
    {
        $this->current?->markHandled(now());
    }

    public function current(): ?WebhookCapture
    {
        return $this->current;
    }

    /**
     * Record an exception reported while handling what looks like a
     * Cashier webhook request, from the exception reporting hook.
     */
    public function recordFailure(WebhookFailure $failure): void
    {
        $this->failure = $failure;
    }

    public function failure(): ?WebhookFailure
    {
        return $this->failure;
    }

    /**
     * Record the final response status for a Cashier webhook request, from
     * the terminating middleware. Reliable fallback signal for abnormal
     * endings that the exception reporting hook didn't observe.
     */
    public function recordTerminatedStatus(int $status): void
    {
        $this->terminatedStatus = $status;
    }

    public function terminatedStatus(): ?int
    {
        return $this->terminatedStatus;
    }
}
