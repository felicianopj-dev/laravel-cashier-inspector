<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_inspector_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('cashier_inspector_events')
                ->cascadeOnDelete();
            $table->string('rule');
            $table->string('code')->index();
            $table->string('severity');
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_inspector_diagnostics');
    }
};
