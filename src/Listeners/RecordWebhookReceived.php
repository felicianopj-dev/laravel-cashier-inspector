<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Support\WebhookCapture;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Laravel\Cashier\Events\WebhookReceived;

class RecordWebhookReceived
{
    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    public function handle(WebhookReceived $event): void
    {
        $this->context->start(WebhookCapture::fromPayload($event->payload));
    }
}
