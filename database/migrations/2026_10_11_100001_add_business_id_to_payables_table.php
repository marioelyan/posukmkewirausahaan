<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 (Q7.1): persisted ownership key on the financial ledger.
 *
 * `payables.business_id` is the authoritative tenant owner once populated by
 * the validated backfill (business:financial-ownership-backfill) or manual
 * assignment (FIN-02). FK uses RESTRICT (design §1.2 / D4): businesses are
 * archived, never hard-deleted, so an accidental delete must fail loudly
 * instead of silently orphaning financial records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            if (! Schema::hasColumn('payables', 'business_id')) {
                $table->foreignId('business_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('businesses')
                    ->restrictOnDelete();
            }

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'due_date']);
            $table->index(['business_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'status']);
            $table->dropIndex(['business_id', 'due_date']);
            $table->dropIndex(['business_id', 'supplier_id']);
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
