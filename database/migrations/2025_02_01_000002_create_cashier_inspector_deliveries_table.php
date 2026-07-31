<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_inspector_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('cashier_inspector_events')
                ->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('severity')->nullable()->index();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->longText('exception_trace')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_inspector_deliveries');
    }
};
