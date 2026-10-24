<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K3 Konsinyasi: gerakan kas pelunasan_hutang tertaut ke hutang yang
     * dibayarnya. nullOnDelete: menghapus hutang tidak menghapus jejak
     * kas (gerakan tetap tercatat sebagai pelunasan).
     */
    public function up(): void
    {
        Schema::table('shift_cash_movements', function (Blueprint $table) {
            $table->foreignId('payable_id')->nullable()->after('expense_id')->constrained('payables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_cash_movements', function (Blueprint $table) {
            $table->dropForeign(['payable_id']);
            $table->dropColumn('payable_id');
        });
    }
};
