<?php

namespace FelicianoPJ\CashierInspector\Support;

use Composer\InstalledVersions;
use FelicianoPJ\CashierInspector\Models\InspectorDelivery;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Illuminate\Support\Collection;

/**
 * Plain-text report for pasting into GitHub issues, Discord, support
 * threads, or an LLM. Only ever reads already-redacted diagnostic data —
 * never includes the raw event payload.
 */
class DiagnosticReport
{
    public static function generate(InspectorEvent $event, ?InspectorDelivery $latestDelivery, Collection $diagnostics): string
    {
        $lines = [
            'Laravel Cashier Inspector Report',
            '',
            'Event: '.$event->stripe_event_type,
            'Stripe Event: '.$event->stripe_event_id,
            'Mode: '.($event->livemode ? 'live' : 'test'),
            'Cashier: '.(self::packageVersion('laravel/cashier') ?? 'unknown'),
            'Laravel: '.app()->version(),
            'PHP: '.PHP_VERSION,
        ];

        if ($latestDelivery) {
            $lines[] = 'Status: '.$latestDelivery->status->value;
        }

        $lines[] = '';
        $lines[] = 'Diagnosis:';

        if ($diagnostics->isEmpty()) {
            $lines[] = 'No diagnostic rules triggered for this event.';
        } else {
            foreach ($diagnostics as $diagnostic) {
                $lines[] = '';
                $lines[] = "[{$diagnostic->severity->value}] {$diagnostic->title}";
                $lines[] = $diagnostic->message;

                foreach ($diagnostic->context ?? [] as $key => $value) {
                    if ($key === 'suggested_checks' || is_array($value)) {
                        continue;
                    }

                    $lines[] = ucfirst(str_replace('_', ' ', $key)).': '.$value;
                }
            }
        }

        $suggestedChecks = $diagnostics
            ->flatMap(fn ($diagnostic) => $diagnostic->context['suggested_checks'] ?? [])
            ->unique()
            ->values();

        if ($suggestedChecks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Suggested checks:';

            foreach ($suggestedChecks as $index => $check) {
                $lines[] = ($index + 1).'. '.$check;
            }
        }

        return implode("\n", $lines);
    }

    protected static function packageVersion(string $package): ?string
    {
        return InstalledVersions::isInstalled($package)
            ? InstalledVersions::getPrettyVersion($package)
            : null;
    }
}
