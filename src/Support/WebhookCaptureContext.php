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

    public function start(WebhookCapture $capture): void
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
}
