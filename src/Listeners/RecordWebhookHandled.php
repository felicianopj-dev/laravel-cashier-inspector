<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use Laravel\Cashier\Events\WebhookHandled;

class RecordWebhookHandled
{
    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    public function handle(WebhookHandled $event): void
    {
        $this->context->finish();
    }
}
