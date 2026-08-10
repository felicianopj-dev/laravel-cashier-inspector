<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_inspector_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('stripe_event_type')->index();
            $table->string('stripe_api_version')->nullable();
            $table->boolean('livemode')->default(false);
            $table->json('payload')->nullable();
            $table->string('customer_id')->nullable()->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('invoice_id')->nullable();
            $table->string('checkout_session_id')->nullable();
            $table->nullableMorphs('billable');
            $table->timestamps();

            // Pruning selects on this column.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_inspector_events');
    }
};
