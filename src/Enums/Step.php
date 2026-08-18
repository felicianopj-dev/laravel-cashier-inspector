<?php

namespace FelicianoPJ\CashierInspector\Enums;

/**
 * The phases of a webhook request this package can honestly observe.
 *
 * Cashier's controller dispatches WebhookReceived, calls its handler and
 * dispatches WebhookHandled, so everything the handler itself does is a
 * black box between two events. These five are the boundaries that are
 * actually measurable from outside it.
 */
enum Step: string
{
    case RequestReceived = 'request_received';
    case EventCaptured = 'event_captured';
    case CashierHandler = 'cashier_handler';
    case Diagnostics = 'diagnostics';
    case Response = 'response';

    public function label(): string
    {
        return match ($this) {
            self::RequestReceived => 'Request received',
            self::EventCaptured => 'Event captured',
            self::CashierHandler => 'Cashier handler',
            self::Diagnostics => 'Diagnostics',
            self::Response => 'Response',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RequestReceived => 'The request reached Cashier\'s webhook route. The gap to the next step covers signature verification and decoding the payload.',
            self::EventCaptured => 'This package read the event and resolved the local billable model.',
            self::CashierHandler => 'Cashier\'s own handler ran. Any other listeners your application registers on Cashier\'s webhook events are inside this window too.',
            self::Diagnostics => 'The diagnostic rules ran against the captured event.',
            self::Response => 'The response was sent back to Stripe.',
        };
    }
}
