<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * customer_id and subscription_id were indexed from the start because
     * the dashboard filters on them. invoice_id was not, since nothing
     * queried it - correlating an event that names only its invoice back
     * to a customer now does, on every capture that arrives without one.
     */
    public function up(): void
    {
        Schema::table('cashier_inspector_events', function (Blueprint $table) {
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_inspector_events', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
        });
    }
};
