<?php

namespace FelicianoPJ\CashierInspector\Support;

use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/**
 * Links an event to whatever Telescope recorded while the same webhook was
 * being processed. Telescope is never required: nothing here runs unless the
 * host application already has it.
 *
 * There is no entry to link to directly. Telescope generates its batch id
 * inside store(), at terminate, so no uuid exists while the webhook is being
 * handled. Tags are applied as entries are recorded, and Telescope's own UI
 * reads a tag out of the query string, so tagging the request's entries with
 * the Stripe event id is what makes a link possible at all.
 */
class TelescopeIntegration
{
    public const TAG = 'cashier-inspector';

    public function __construct(protected WebhookCaptureContext $context)
    {
    }

    /**
     * Whether links should be offered: Telescope installed, switched on, and
     * not suppressed here.
     */
    public static function available(): bool
    {
        return class_exists(Telescope::class)
            && (bool) config('telescope.enabled', true)
            && (bool) config('cashier-inspector.integrations.telescope', true);
    }

    public function register(): void
    {
        if (! static::available()) {
            return;
        }

        Telescope::tag(fn (IncomingEntry $entry) => $this->tags());
    }

    /**
     * The tags for whatever is being recorded right now. Empty on every
     * request that isn't a Stripe webhook, which is the common case.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $capture = $this->context->current();

        if (! $capture) {
            return [];
        }

        return [
            self::TAG,
            static::eventTag($capture->stripeEventId),
        ];
    }

    public static function eventTag(string $stripeEventId): string
    {
        return 'stripe-event:'.$stripeEventId;
    }

    /**
     * A link into Telescope's request list, filtered to this event's tag.
     * Null when Telescope isn't there to link to.
     */
    public static function urlFor(InspectorEvent $event): ?string
    {
        if (! static::available()) {
            return null;
        }

        $path = trim((string) config('telescope.path', 'telescope'), '/');

        return url($path.'/requests').'?tag='.urlencode(static::eventTag($event->stripe_event_id));
    }
}
