<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 (Q7.1): persisted ownership key on the financial ledger.
 *
 * See `2026_10_11_100001_add_business_id_to_payables_table.php` for the
 * ownership/FK rationale (restrict, archives instead of hard delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (! Schema::hasColumn('receivables', 'business_id')) {
                $table->foreignId('business_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('businesses')
                    ->restrictOnDelete();
            }

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'due_date']);
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'status']);
            $table->dropIndex(['business_id', 'due_date']);
            $table->dropIndex(['business_id', 'customer_id']);
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
