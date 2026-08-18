<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_inspector_steps', function (Blueprint $table) {
            $table->id();

            // Keyed on the delivery rather than the event: a timeline
            // describes one attempt, and an event redelivered three times
            // would otherwise interleave three timelines with no way to
            // tell them apart.
            $table->foreignId('delivery_id')
                ->constrained('cashier_inspector_deliveries')
                ->cascadeOnDelete();

            $table->string('step');
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_inspector_steps');
    }
};
