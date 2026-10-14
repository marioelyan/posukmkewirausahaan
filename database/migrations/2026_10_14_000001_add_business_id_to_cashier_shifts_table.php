<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted business ownership for cashier shifts.
 *
 * Until now `cashier_shifts` had no `business_id` at all, so its ownership could
 * only be *derived* by joining the owning warehouse. Because the shift row
 * itself carried no tenant key, a plain `CashierShift::query()` was an unscoped
 * cross-business read and every call site that forgot the join leaked another
 * tenant's shift (see the shared `activeCashierShift` prop, the shift history
 * list, and the outlet switcher lock). Making the column explicit lets
 * `BelongsToBusiness` scope every read/write by default instead of relying on
 * each query to remember the relation.
 *
 * The column is nullable and additive so existing installs keep working before
 * the derivation below runs; the shift -> warehouse -> business mapping is
 * deterministic, so existing rows are filled in place (a fresh install has no
 * rows and this is a no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cashier_shifts') || Schema::hasColumn('cashier_shifts', 'business_id')) {
            return;
        }

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('user_id')
                ->constrained('businesses')
                ->restrictOnDelete();

            $table->index(['business_id', 'status']);
        });

        // Derive ownership for rows that predate the column. A correlated
        // subquery keeps this portable (SQLite in tests, MySQL in production)
        // and rows with a warehouse-less legacy shift stay NULL on purpose:
        // they are unresolvable under tenancy, which is the same fail-closed
        // behaviour the shift gate already had.
        DB::update(<<<'SQL'
            UPDATE cashier_shifts
            SET business_id = (
                SELECT warehouses.business_id
                FROM warehouses
                WHERE warehouses.id = cashier_shifts.warehouse_id
            )
            WHERE business_id IS NULL
              AND warehouse_id IS NOT NULL
              AND EXISTS (
                SELECT 1 FROM warehouses
                WHERE warehouses.id = cashier_shifts.warehouse_id
                  AND warehouses.business_id IS NOT NULL
              )
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cashier_shifts') || ! Schema::hasColumn('cashier_shifts', 'business_id')) {
            return;
        }

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'status']);
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
