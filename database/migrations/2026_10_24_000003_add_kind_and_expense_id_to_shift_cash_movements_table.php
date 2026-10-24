<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->string('kind')->nullable()->after('type');
            $table->foreignId('expense_id')->nullable()->after('kind')->constrained()->nullOnDelete();
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
            $table->dropForeign(['expense_id']);
            $table->dropColumn(['kind', 'expense_id']);
        });
    }
};
