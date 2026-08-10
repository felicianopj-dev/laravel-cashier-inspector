<?php

namespace FelicianoPJ\CashierInspector\Support;

/**
 * Holds the webhook currently being processed by Cashier's controller,
 * bridging the WebhookReceived and WebhookHandled listeners so the handled
 * side knows when its matching event was received.
 *
 * Bound as a container singleton, so it outlives a single request under a
 * long-running worker (Octane, queue workers). start() runs at the top of
 * every webhook request and is what keeps that from leaking: it holds no
 * state that isn't replaced there.
 */
class WebhookCaptureContext
{
    protected ?WebhookCapture $current = null;

    /**
     * Accepts null so the receiving listener can start every webhook
     * request unconditionally, including ones it couldn't capture — that
     * null is what clears a previous request's capture.
     */
    public function start(?WebhookCapture $capture): void
    {
        $this->current = $capture;
    }

    public function current(): ?WebhookCapture
    {
        return $this->current;
    }
}
