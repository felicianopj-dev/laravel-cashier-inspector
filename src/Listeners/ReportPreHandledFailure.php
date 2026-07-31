<?php

namespace FelicianoPJ\CashierInspector\Listeners;

use FelicianoPJ\CashierInspector\Support\CashierWebhookRoute;
use FelicianoPJ\CashierInspector\Support\WebhookCaptureContext;
use FelicianoPJ\CashierInspector\Support\WebhookFailure;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Registered against the framework's exception reporting hook to capture
 * exceptions thrown while Cashier's webhook controller was handling a
 * request, before WebhookHandled fires.
 */
class ReportPreHandledFailure
{
    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    public function __invoke(Throwable $e): void
    {
        if (! CashierWebhookRoute::matches(request())) {
            return;
        }

        $this->context->recordFailure(new WebhookFailure(
            exceptionClass: get_class($e),
            exceptionMessage: $e->getMessage(),
            exceptionTrace: $e->getTraceAsString(),
            occurredAt: Carbon::now(),
        ));
    }
}
