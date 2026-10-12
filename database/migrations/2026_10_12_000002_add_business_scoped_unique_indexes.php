<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE-01..07 (Q4, decision B — per-business): replace the global unique
 * indexes on identifiers owned by a business with composite
 * `(business_id, column)` indexes.
 *
 * A composite unique treats `business_id IS NULL` rows as distinct (MySQL),
 * so swapping it in while legacy rows still have a null business_id would
 * silently drop uniqueness for single-tenant installs. Each table is therefore
 * swapped only when every row already carries a business_id (i.e. after
 * `business:backfill`). Tables with unbackfilled rows keep the legacy global
 * index untouched so no data-integrity guard is lost.
 *
 * Deferred by decision: UNIQUE-04 (units.code stays global), UNIQUE-08/09
 * (customer_vouchers / customer_segments have no business_id column and need
 * separate schema approval), UNIQUE-10 (documents + number generators).
 */
return new class extends Migration
{
    /**
     * [table, legacyIndex, column, compositeIndex]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $swaps = [
        ['products', 'products_barcode_unique', 'barcode', 'products_business_barcode_unique'],
        ['products', 'products_sku_unique', 'sku', 'products_business_sku_unique'],
        ['price_lists', 'price_lists_slug_unique', 'slug', 'price_lists_business_slug_unique'],
        ['warehouses', 'warehouses_code_unique', 'code', 'warehouses_business_code_unique'],
        ['outlets', 'outlets_code_unique', 'code', 'outlets_business_code_unique'],
        ['customers', 'customers_member_code_unique', 'member_code', 'customers_business_member_code_unique'],
    ];

    public function up(): void
    {
        foreach ($this->swaps as [$table, $legacyIndex, $column, $compositeIndex]) {
            $this->swap($table, $legacyIndex, $column, $compositeIndex, up: true);
        }
    }

    public function down(): void
    {
        foreach ($this->swaps as [$table, $legacyIndex, $column, $compositeIndex]) {
            $this->swap($table, $legacyIndex, $column, $compositeIndex, up: false);
        }
    }

    private function swap(string $table, string $legacyIndex, string $column, string $compositeIndex, bool $up): void
    {
        if (! Schema::hasColumn($table, 'business_id')) {
            return;
        }

        $ready = DB::table($table)->whereNull('business_id')->doesntExist();

        if (! $ready) {
            // Unbackfilled rows exist — keep the legacy index so single-tenant
            // installs do not lose their database-level uniqueness guard.
            return;
        }

        if ($up) {
            Schema::table($table, function (Blueprint $blueprint) use ($legacyIndex, $column, $compositeIndex) {
                $blueprint->dropUnique($legacyIndex);
                $blueprint->unique(['business_id', $column], $compositeIndex);
            });
        } elseif (Schema::hasIndex($table, $compositeIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($legacyIndex, $column, $compositeIndex) {
                $blueprint->dropUnique($compositeIndex);
                $blueprint->unique($column, $legacyIndex);
            });
        }
    }
};
