<?php

namespace FelicianoPJ\CashierInspector\Support;

use Illuminate\Support\Carbon;

final class WebhookFailure
{
    public function __construct(
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $exceptionTrace,
        public readonly Carbon $occurredAt,
    ) {
    }
}
