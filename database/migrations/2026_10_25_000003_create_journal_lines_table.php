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
        Schema::dropIfExists('journal_lines');

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('journal_entries')->cascadeOnDelete();
            // RESTRICT: an account that already has journal lines must not
            // disappear from the audit trail.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            // Integer Rupiah. Exactly one side is filled per line (spec §4);
            // enforced by JournalService, not by a CHECK constraint, because
            // SQLite cannot ADD a CHECK via ALTER and the migration must
            // stay portable across both engines.
            $table->unsignedInteger('debit')->default(0);
            $table->unsignedInteger('credit')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('journal_lines');
    }
};
