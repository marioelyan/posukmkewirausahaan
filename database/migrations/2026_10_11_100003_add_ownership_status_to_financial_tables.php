<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01/05: six-state ownership lifecycle on the financial ledger.
 *
 * `ownership_status` values (see App\Services\FinancialOwnershipService):
 *   unassigned     — not yet evaluated (default / legacy)
 *   resolved       — ownership proven by validated backfill or manual assignment
 *   manual_required— no provable parent chain (FIN-02 assignment target)
 *   ambiguous      — chain resolves to >1 business, or persisted-vs-chain conflict
 *   dangling       — a required parent/intermediate row is missing
 *   unsupported    — record shape not supported by the verified ownership model
 *
 * Evaluation writes status ONLY (never a business guess). business_id is written
 * exclusively by the apply-mode backfill (@see FinancialOwnershipService::applyBackfill)
 * or explicit manual assignment (FIN-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payables', 'receivables'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('ownership_status', 20)
                    ->default('unassigned')
                    ->index();
                $table->timestamp('ownership_evaluated_at')->nullable();
                $table->text('ownership_note')->nullable();
                $table->index(['business_id', 'ownership_status']);
            });
        }
    }

    public function down(): void
    {
        foreach (['payables', 'receivables'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['business_id', 'ownership_status']);
                $table->dropColumn(['ownership_note', 'ownership_evaluated_at', 'ownership_status']);
            });
        }
    }
};
