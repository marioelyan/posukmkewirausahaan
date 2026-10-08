<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('hold_id')->nullable()->after('price');
            $table->string('hold_label')->nullable()->after('hold_id');
            $table->timestamp('held_at')->nullable()->after('hold_label');

            $table->index('hold_id');
            $table->index(['cashier_id', 'hold_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // The composite index backs the cashier_id FK, so drop the FK first.
            $table->dropForeign(['cashier_id']);
            $table->dropIndex(['cashier_id', 'hold_id']);
            $table->dropIndex(['hold_id']);
            $table->dropColumn(['hold_id', 'hold_label', 'held_at']);
            $table->foreign('cashier_id')->references('id')->on('users');
        });
    }
};
