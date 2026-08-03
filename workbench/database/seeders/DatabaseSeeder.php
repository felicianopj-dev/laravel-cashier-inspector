<?php

namespace Workbench\Database\Seeders;

use FelicianoPJ\CashierInspector\Diagnostics\DiagnosticEngine;
use FelicianoPJ\CashierInspector\Enums\EventStatus;
use FelicianoPJ\CashierInspector\Enums\Severity;
use FelicianoPJ\CashierInspector\Models\InspectorEvent;
use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // UserFactory::new()->times(10)->create();

        UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->seedHealthyEvent();
        $this->seedFailedEvent();
        $this->seedUnmatchedEvent();
        $this->seedRedeliveredEvent();
        $this->seedLiveModeEvent();
    }

    /**
     * Handled event with a redacted payload — exercises the "show payload"
     * panel and the happy path through the dashboard.
     */
    protected function seedHealthyEvent(): void
    {
        $event = InspectorEvent::create(array_merge([
            'stripe_event_id' => 'evt_seed_healthy',
            'stripe_event_type' => 'customer.subscription.updated',
            'livemode' => false,
            'customer_id' => 'cus_seed_healthy',
            'subscription_id' => 'sub_seed_healthy',
        ], $this->redactedPayload('cus_seed_healthy', 'jane@example.com')));

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
     * Failed event with an exception — triggers ProcessingExceptionRule.
     */
    protected function seedFailedEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_failed',
            'stripe_event_type' => 'invoice.payment_failed',
            'livemode' => false,
            'customer_id' => 'cus_seed_failed',
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
     * Event type Cashier has no handler for — triggers UnhandledWebhookRule.
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
     * — triggers DuplicateDeliveryRule and populates the delivery-attempts
     * table with more than one row.
     */
    protected function seedRedeliveredEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_redelivered',
            'stripe_event_type' => 'customer.subscription.deleted',
            'livemode' => false,
            'customer_id' => 'cus_seed_redelivered',
            'subscription_id' => 'sub_seed_redelivered',
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
     * Live-mode event, so the dashboard's test/live filter has both kinds
     * of data to filter on.
     */
    protected function seedLiveModeEvent(): void
    {
        $event = InspectorEvent::create([
            'stripe_event_id' => 'evt_seed_live',
            'stripe_event_type' => 'checkout.session.completed',
            'livemode' => true,
            'customer_id' => 'cus_seed_live',
            'checkout_session_id' => 'cs_seed_live',
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
     * @return array{payload: array}
     */
    protected function redactedPayload(string $customerId, string $email): array
    {
        $payload = [
            'id' => 'evt_seed_healthy',
            'data' => [
                'object' => [
                    'id' => $customerId,
                    'customer_email' => $email,
                    'metadata' => ['internal_note' => 'seeded for manual testing'],
                ],
            ],
        ];

        return ['payload' => app(PayloadRedactor::class)->redact($payload)];
    }

    protected function diagnose(InspectorEvent $event): void
    {
        app(DiagnosticEngine::class)->run($event);
    }
}
