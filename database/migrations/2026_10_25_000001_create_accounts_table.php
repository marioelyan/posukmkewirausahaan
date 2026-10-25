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
        // Guard against the partial-table artifact pattern proven in the
        // 2026-10-25 v2 deploy failure: a failed MySQL migration leaves a
        // partial table behind with no migration record, so a fixed re-run
        // must drop it first. No-op on fresh databases.
        Schema::dropIfExists('accounts');

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            // Nullable + SET NULL, following the financial-tables pattern
            // (payables/receivables/expenses): a NOT NULL column with
            // ON DELETE SET NULL is refused by MySQL (error 1830).
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 10);
            $table->string('name');
            // aset / kewajiban / modal / pendapatan / beban (spec §4).
            $table->string('category', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Note: with a nullable business_id, MySQL treats NULLs as
            // distinct in unique indexes, so this constraint only
            // deduplicates within a real business. The null-business
            // scope relies on firstOrCreate in the seeder/service.
            $table->unique(['business_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('accounts');
    }
};
