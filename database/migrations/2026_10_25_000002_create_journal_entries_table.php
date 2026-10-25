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
        // Same partial-table guard as the accounts migration (see it).
        Schema::dropIfExists('journal_entries');

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            // Polymorphic source, e.g. Transaction, JournalReversal.
            // No FK by design (polymorphic); idempotency is enforced by
            // the unique index below plus the service-level lookup.
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            // YYYY-MM; only used by PPh period entries (spec §3.10).
            $table->string('period', 7)->nullable();
            // Self-reference for reversing entries (spec §4). One direct
            // reversal per entry: see JournalService::reverse().
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('journal_entries');
    }
};
