<?php

namespace Workbench\Database\Seeders;

use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

/**
 * Seeds the workbench with data to click through manually.
 *
 * Two things matter for this to be useful. Billable models and their
 * Cashier subscriptions are seeded first, so events that reference them
 * resolve cleanly - without that, every event raises "missing billable
 * model" and "no local subscription", and the noise buries whatever the
 * scenario was meant to show. And there is enough volume to paginate,
 * since the list is 25 rows to a page and bugs that only appear on page
 * two are invisible with five rows of seed data.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Stripe customer id given to the primary seeded user, so events
     * referencing it resolve to a real local billable model.
     */
    protected const CUSTOMER = 'cus_seed_user';

    protected User $user;

    public function run(): void
    {
        $this->user = UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->user->forceFill(['stripe_id' => self::CUSTOMER])->save();

        $this->seedLocalSubscription('sub_seed_healthy', 'price_seed_monthly');
        $this->seedLocalSubscription('sub_seed_redelivered', 'price_seed_monthly', 'canceled');

        $this->seedHealthyEvent();
        $this->seedFailedEvent();
        $this->seedUnmatchedEvent();
        $this->seedRedeliveredEvent();
        $this->seedLiveModeEvent();
        $this->seedSlowEvent();
        $this->seedMissingBillableEvent();
        $this->seedMissingSubscriptionEvent();
        $this->seedDuplicateSubscriptionTypeUser();
        $this->seedFillerEvents();
    }

    /**
     * A Cashier subscription for the seeded user, so events referencing it
     * are not reported as missing. Mirrors what Cashier's own webhook
     * handler would have written.
     */
    protected function seedLocalSubscription(string $stripeId, string $price, string $status = 'active'): void
    {
        $subscription = $this->user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'ends_at' => $status === 'canceled' ? now()->subDay() : null,
        ]);

        $subscription->items()->create([
            'stripe_id' => "si_{$stripeId}",
            'stripe_product' => 'prod_seed',
            'stripe_price' => $price,
            'quantity' => 1,
        ]);
    }

    /**
     * The control: handled, resolves to a real billable model and a real
     * local subscription, so nothing flags it. Stays out of the
     * problems-only view, and exercises the redacted payload panel.
     */
    protected function seedHealthyEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_healthy',
            'stripe_event_type' => 'customer.subscription.updated',
            'livemode' => false,
            'customer_id' => self::CUSTOMER,
            'subscription_id' => 'sub_seed_healthy',
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
            'payload' => $this->redactedPayload('evt_seed_healthy'),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(10),
            'handled_at' => now()->subMinutes(10)->addMilliseconds(120),
            'duration_ms' => 120,
        ]);

        $this->diagnose($event);
    }

    /**
     * Failed event with an exception - triggers ProcessingExceptionRule.
     */
    protected function seedFailedEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_failed',
            'stripe_event_type' => 'invoice.payment_failed',
            'livemode' => false,
            'customer_id' => self::CUSTOMER,
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Failed,
            'severity' => Severity::Error,
            'received_at' => now()->subMinutes(20),
            'duration_ms' => 45,
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Unable to locate local subscription for sub_missing.',
        ]);

        $this->diagnose($event);
    }

    /**
     * Event type Cashier has no handler for - triggers UnhandledWebhookRule.
     */
    protected function seedUnmatchedEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_unmatched',
            'stripe_event_type' => 'payment_intent.created',
            'livemode' => false,
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Unmatched,
            'severity' => Severity::Info,
            'received_at' => now()->subMinutes(30),
            'duration_ms' => 8,
        ]);

        $this->diagnose($event);
    }

    /**
     * Two delivery attempts (a failed retry, then a successful redelivery)
     * - triggers DuplicateDeliveryRule and puts more than one row in the
     * delivery-attempts table on the event page.
     */
    protected function seedRedeliveredEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_redelivered',
            'stripe_event_type' => 'customer.subscription.deleted',
            'livemode' => false,
            'customer_id' => self::CUSTOMER,
            'subscription_id' => 'sub_seed_redelivered',
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Failed,
            'severity' => Severity::Error,
            'received_at' => now()->subMinutes(40),
            'duration_ms' => 30,
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Timed out contacting the database.',
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(1),
            'handled_at' => now(),
            'duration_ms' => 95,
        ]);

        $this->diagnose($event);
    }

    /**
     * Live-mode event, so the test/live filter has both kinds of data.
     */
    protected function seedLiveModeEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_live',
            'stripe_event_type' => 'checkout.session.completed',
            'livemode' => true,
            'customer_id' => self::CUSTOMER,
            'checkout_session_id' => 'cs_seed_live',
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(2),
            'handled_at' => now()->subMinutes(2)->addMilliseconds(60),
            'duration_ms' => 60,
        ]);

        $this->diagnose($event);
    }

    /**
     * Handled, but well past the slow-processing threshold - triggers
     * SlowProcessingRule, which nothing else here exercises.
     */
    protected function seedSlowEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_slow',
            'stripe_event_type' => 'customer.subscription.updated',
            'livemode' => false,
            'customer_id' => self::CUSTOMER,
            'subscription_id' => 'sub_seed_healthy',
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(6),
            'handled_at' => now()->subMinutes(6)->addMilliseconds(8400),
            'duration_ms' => 8400,
        ]);

        $this->diagnose($event);
    }

    /**
     * A customer id no local billable model matches - triggers
     * MissingBillableModelRule on its own rather than on every event.
     */
    protected function seedMissingBillableEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_no_billable',
            'stripe_event_type' => 'customer.subscription.updated',
            'livemode' => false,
            'customer_id' => 'cus_seed_unknown',
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(15),
            'handled_at' => now()->subMinutes(15)->addMilliseconds(40),
            'duration_ms' => 40,
        ]);

        $this->diagnose($event);
    }

    /**
     * A subscription id Cashier's own table doesn't have - triggers
     * MissingLocalSubscriptionRule.
     */
    protected function seedMissingSubscriptionEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_no_local_sub',
            'stripe_event_type' => 'customer.subscription.deleted',
            'livemode' => false,
            'customer_id' => self::CUSTOMER,
            'subscription_id' => 'sub_seed_never_created',
            'billable_type' => User::class,
            'billable_id' => $this->user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(25),
            'handled_at' => now()->subMinutes(25)->addMilliseconds(35),
            'duration_ms' => 35,
        ]);

        $this->diagnose($event);
    }

    /**
     * A second billable model holding two valid subscriptions of the same
     * Cashier type - triggers DuplicateSubscriptionTypeRule. Kept on its
     * own user so it doesn't flag every event belonging to the primary one.
     */
    protected function seedDuplicateSubscriptionTypeUser(): void
    {
        $user = UserFactory::new()->create([
            'name' => 'Duplicate Type User',
            'email' => 'duplicate@example.com',
        ]);

        $user->forceFill(['stripe_id' => 'cus_seed_duplicate_type'])->save();

        foreach (['sub_seed_dup_a', 'sub_seed_dup_b'] as $stripeId) {
            $user->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => $stripeId,
                'stripe_status' => 'active',
                'stripe_price' => 'price_seed_monthly',
                'quantity' => 1,
            ]);
        }

        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_duplicate_type',
            'stripe_event_type' => 'customer.subscription.updated',
            'livemode' => false,
            'customer_id' => 'cus_seed_duplicate_type',
            'subscription_id' => 'sub_seed_dup_b',
            'billable_type' => User::class,
            'billable_id' => $user->getKey(),
        ]);

        $event->deliveries()->create([
            'status' => EventStatus::Handled,
            'severity' => Severity::Success,
            'received_at' => now()->subMinutes(8),
            'handled_at' => now()->subMinutes(8)->addMilliseconds(50),
            'duration_ms' => 50,
        ]);

        $this->diagnose($event);
    }

    /**
     * Bulk history so both views run past the 25-row page size: enough
     * unmatched events to paginate the problems-only view, and enough
     * handled ones that the "show all" view paginates further. Spread over
     * days so the received-date filter has a range to bite on.
     */
    protected function seedFillerEvents(): void
    {
        foreach (range(1, 40) as $i) {
            $unmatched = $i % 2 === 0;
            $receivedAt = now()->subHours($i * 3);

            $event = InspectorEvent::create([
                'stripe_event_id' => sprintf('evt_seed_filler_%02d', $i),
                'stripe_event_type' => $unmatched ? 'payment_intent.succeeded' : 'invoice.paid',
                'livemode' => false,
                'customer_id' => self::CUSTOMER,
                'invoice_id' => $unmatched ? null : sprintf('in_seed_filler_%02d', $i),
                'billable_type' => User::class,
                'billable_id' => $this->user->getKey(),
            ]);

            $event->deliveries()->create([
                'status' => $unmatched ? EventStatus::Unmatched : EventStatus::Handled,
                'severity' => $unmatched ? Severity::Info : Severity::Success,
                'received_at' => $receivedAt,
                'handled_at' => $unmatched ? null : $receivedAt->clone()->addMilliseconds(30),
                'duration_ms' => $unmatched ? 12 : 30,
            ]);

            $this->diagnose($event);
        }
    }

    /**
     * Carries the personal fields the default redaction paths cover, on
     * both the object and previous_attributes, so the payload panel shows
     * redaction actually doing something.
     */
    protected function redactedPayload(string $eventId): array
    {
        return app(PayloadRedactor::class)->redact([
            'id' => $eventId,
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'object' => 'subscription',
                    'id' => 'sub_seed_healthy',
                    'customer' => self::CUSTOMER,
                    'status' => 'active',
                    'email' => 'jane@example.com',
                    'name' => 'Jane Doe',
                    'phone' => '+15550000000',
                    'address' => ['line1' => '1 Main St', 'city' => 'Lisbon'],
                    'metadata' => ['internal_note' => 'seeded for manual testing'],
                ],
                'previous_attributes' => [
                    'email' => 'old-address@example.com',
                    'name' => 'Jane Older',
                ],
            ],
        ]);
    }

    protected function diagnose(InspectorEvent $event): void
    {
        app(DiagnosticEngine::class)->run($event);
    }
}
