<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q12 — business ownership for audit logs.
     *
     * Nullable and additive so existing installs keep working before any
     * backfill runs. Do NOT make this NOT NULL until every legacy row has
     * been mapped and verified (see the production runbook). While tenancy is
     * disabled the column stays NULL and behaviour is unchanged; historical
     * rows written before the flag was enabled remain NULL and are treated as
     * legacy (hidden from business-scoped viewers).
     */
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || Schema::hasColumn('audit_logs', 'business_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained('businesses')
                ->nullOnDelete();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'business_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropIndex(['business_id']);
            $table->dropColumn('business_id');
        });
    }
};
