<?php

/**
 * The custom rule extension point, exercised the way the README documents
 * it: a rule outside this package's namespace, registered through config
 * alone, resolved from the container, resolving the event's billable model
 * from its stored type and id, and returning a result whose context and
 * suggested checks are persisted.
 *
 * The rule below is the README's example verbatim. If this test needs
 * editing, the README needs editing too - a change here is a breaking
 * change for anyone who wrote a rule against the documented contract.
 */

use FelicianoPJ\CashierInspector\Contracts\DiagnosticRule;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticResult;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use Laravel\Cashier\Cashier;
use Workbench\App\Models\User;

class RefundOnNewSubscriptionRule implements DiagnosticRule
{
    public function supports(InspectorEvent $event): bool
    {
        return $event->stripe_event_type === 'charge.refunded'
            && $event->billable_id !== null;
    }

    public function diagnose(InspectorEvent $event): DiagnosticResult
    {
        $billable = $event->billable_type::find($event->billable_id);

        $subscription = $billable?->subscriptions()
            ->where('created_at', '>', now()->subDays(7))
            ->first();

        if (! $subscription) {
            return DiagnosticResult::passed();
        }

        return DiagnosticResult::warning(
            code: 'refund_on_new_subscription',
            title: 'Refund on a week-old subscription',
            message: 'This customer was refunded within a week of subscribing.',
            suggestedChecks: [
                'Confirm the subscription was cancelled as well as refunded.',
            ],
            context: [
                'subscription_id' => $subscription->stripe_id,
            ],
        );
    }
}

it('runs a rule registered only through config, as the README documents', function () {
    Cashier::useCustomerModel(User::class);

    $user = User::create([
        'name' => 'Jane',
        'email' => 'jane-readme@example.com',
        'password' => 'secret',
    ]);
    $user->forceFill(['stripe_id' => 'cus_readme'])->save();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_readme',
        'stripe_status' => 'active',
        'stripe_price' => 'price_readme',
        'quantity' => 1,
    ]);

    $event = InspectorEvent::create([
        'stripe_event_id' => 'evt_readme',
        'stripe_event_type' => 'charge.refunded',
        'livemode' => false,
        'customer_id' => 'cus_readme',
        'billable_type' => $user::class,
        'billable_id' => $user->id,
    ]);

    config()->set('cashier-inspector.diagnostics.rules', [RefundOnNewSubscriptionRule::class]);

    app()->make(DiagnosticEngine::class)->run($event->fresh());

    $finding = $event->diagnostics()->sole();

    expect($finding->code)->toBe('refund_on_new_subscription')
        ->and($finding->severity->value)->toBe('warning')
        ->and($finding->context['subscription_id'])->toBe('sub_readme')
        ->and($finding->context['suggested_checks'])->toHaveCount(1);

    Cashier::useCustomerModel('App\Models\User');
});
