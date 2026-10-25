<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 slice 2 (spec §3.8, keputusan Q5): setoran keluar wajib punya
 * tujuan — rekening bank (butuh bank_account_id, jurnal Dr 1110 Bank)
 * atau pemilik (jurnal Dr 3110 Prive).
 *
 * Both columns are nullable so pre-existing rows stay valid: old setoran
 * rows without a destination are left unjournaled (the backfill slice
 * decides them), never force-mapped to an account.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_cash_movements', function (Blueprint $table) {
            $table->string('setoran_destination')->nullable()->after('kind');
            $table->foreignId('bank_account_id')->nullable()->after('setoran_destination')->constrained('bank_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shift_cash_movements', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn(['setoran_destination', 'bank_account_id']);
        });
    }
};
