<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every insights/sales/profit aggregate filters on
     * `payment_status = 'paid'` together with a warehouse scope and a date
     * range, but the table only had an index on (warehouse_id, created_at).
     * This composite puts the highly selective status column first so the
     * planner can narrow before scanning the date range.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(
                ['payment_status', 'warehouse_id', 'created_at'],
                'transactions_payment_status_warehouse_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_payment_status_warehouse_created_at_index');
        });
    }
};
