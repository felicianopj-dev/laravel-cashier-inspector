<?php

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorDiagnostic;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;

$makeInspectorEvent = function (string $id = 'evt_diag'): InspectorEvent {
    return InspectorEvent::create([
        'stripe_event_id' => $id,
        'stripe_event_type' => 'customer.subscription.updated',
        'livemode' => false,
    ]);
};

class DiagnosticEngineTestAlwaysWarnsRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return true;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        return DiagnosticResult::warning('always_warns', 'Always warns', 'This rule always triggers.');
    }
}

it('persists a triggered result with its rule and code', function () use ($makeInspectorEvent) {
    $rule = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            return DiagnosticResult::warning(
                code: 'test_warning',
                title: 'Something looks off',
                message: 'This is a test warning.',
                suggestedChecks: ['Check the thing.'],
                context: ['foo' => 'bar'],
            );
        }
    };

    $event = $makeInspectorEvent();

    (new DiagnosticEngine([$rule]))->run($event);

    $diagnostic = InspectorDiagnostic::where('event_id', $event->id)->sole();

    expect($diagnostic->rule)->toBe($rule::class)
        ->and($diagnostic->code)->toBe('test_warning')
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->title)->toBe('Something looks off')
        ->and($diagnostic->context)->toBe(['foo' => 'bar', 'suggested_checks' => ['Check the thing.']]);
});

it('does not persist passed or skipped results', function () use ($makeInspectorEvent) {
    $passed = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            return DiagnosticResult::passed();
        }
    };

    $skipped = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            return DiagnosticResult::skipped();
        }
    };

    $event = $makeInspectorEvent();

    (new DiagnosticEngine([$passed, $skipped]))->run($event);

    expect(InspectorDiagnostic::where('event_id', $event->id)->count())->toBe(0);
});

it('never calls diagnose when supports returns false', function () use ($makeInspectorEvent) {
    $rule = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return false;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            throw new RuntimeException('diagnose() should never be called.');
        }
    };

    (new DiagnosticEngine([$rule]))->run($makeInspectorEvent());

    expect(true)->toBeTrue();
});

it('replaces previous diagnostics on each run', function () use ($makeInspectorEvent) {
    $callCount = 0;

    $rule = new class($callCount) implements DiagnosticRule
    {
        public function __construct(private int &$callCount)
        {
        }

        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            $this->callCount++;

            return DiagnosticResult::error("run_{$this->callCount}", 'Title', 'Message');
        }
    };

    $event = $makeInspectorEvent();
    $engine = new DiagnosticEngine([$rule]);

    $engine->run($event);
    $engine->run($event);

    $diagnostics = InspectorDiagnostic::where('event_id', $event->id)->get();

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics->first()->code)->toBe('run_2');
});

it('logs and continues when a rule throws', function () use ($makeInspectorEvent) {
    $failing = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            throw new RuntimeException('Boom.');
        }
    };

    $working = new class implements DiagnosticRule
    {
        public function supports(InspectorEvent $event): bool
        {
            return true;
        }

        public function diagnose(InspectorEvent $event): DiagnosticResult
        {
            return DiagnosticResult::info('still_runs', 'Title', 'Message');
        }
    };

    $event = $makeInspectorEvent();

    (new DiagnosticEngine([$failing, $working]))->run($event);

    expect(InspectorDiagnostic::where('event_id', $event->id)->pluck('code')->all())->toBe(['still_runs']);
});

it('does nothing for a null or unknown event id', function () {
    (new DiagnosticEngine([]))->runForEventId(null);
    (new DiagnosticEngine([]))->runForEventId(999999);

    expect(InspectorDiagnostic::count())->toBe(0);
});

it('runs configured rules automatically after a webhook is handled', function () {
    config()->set('cashier-inspector.diagnostics.rules', [DiagnosticEngineTestAlwaysWarnsRule::class]);

    $payload = [
        'id' => 'evt_diag_wired',
        'type' => 'customer.subscription.updated',
        'api_version' => '2024-06-20',
        'livemode' => false,
        'data' => ['object' => ['id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1']],
    ];

    event(new Laravel\Cashier\Events\WebhookReceived($payload));
    event(new Laravel\Cashier\Events\WebhookHandled($payload));

    $event = InspectorEvent::where('stripe_event_id', 'evt_diag_wired')->sole();

    expect(InspectorDiagnostic::where('event_id', $event->id)->pluck('code')->all())->toBe(['always_warns']);
});
