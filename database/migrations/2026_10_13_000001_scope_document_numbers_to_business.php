<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE-10 (Q4, decision B — per-business): document numbers & codes.
 *
 * Adds the missing `business_id` column to the six inventory/document tables
 * (purchase_orders, goods_receivings, supplier_returns, stock_transfers,
 * sales_returns, stock_opnames), then swaps their global unique identifiers
 * for composite `(business_id, column)` indexes:
 *
 *  - document_number -> composite on purchase_orders/goods_receivings/
 *    supplier_returns/stock_transfers
 *  - code            -> composite on sales_returns/stock_opnames
 *
 * The swap is guarded exactly like UNIQUE-01..09 (see
 * 2026_10_12_000002_add_business_scoped_unique_indexes.php): a composite
 * unique treats `business_id IS NULL` rows as distinct (MySQL), so the swap
 * only happens while zero rows are unscoped — what `business:backfill`
 * guarantees. Unbackfilled installs keep the legacy global index untouched so
 * no data-integrity guard is lost.
 *
 * The document number generator (`DocumentNumberGenerator`) sequences per
 * business, but the global unique index remains authoritative until the
 * backfill + swap has run; that is the same dependency chain as the other
 * identifier tables.
 *
 * Deferred by separate decision (register §13.20): adding constraints to the
 * non-unique `payables.document_number` and `transactions.invoice`.
 */
return new class extends Migration
{
    /**
     * [table, column, legacyIndex, compositeIndex]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $swaps = [
        ['purchase_orders', 'document_number', 'purchase_orders_document_number_unique', 'purchase_orders_business_document_number_unique'],
        ['goods_receivings', 'document_number', 'goods_receivings_document_number_unique', 'goods_receivings_business_document_number_unique'],
        ['supplier_returns', 'document_number', 'supplier_returns_document_number_unique', 'supplier_returns_business_document_number_unique'],
        ['stock_transfers', 'document_number', 'stock_transfers_document_number_unique', 'stock_transfers_business_document_number_unique'],
        ['sales_returns', 'code', 'sales_returns_code_unique', 'sales_returns_business_code_unique'],
        ['stock_opnames', 'code', 'stock_opnames_code_unique', 'stock_opnames_business_code_unique'],
    ];

    public function up(): void
    {
        foreach ($this->swaps as [$table, $column, $legacyIndex, $compositeIndex]) {
            $this->addBusinessColumn($table);
            $this->swapUnique($table, $column, $legacyIndex, $compositeIndex, up: true);
        }
    }

    public function down(): void
    {
        foreach ($this->swaps as [$table, $column, $legacyIndex, $compositeIndex]) {
            $this->swapUnique($table, $column, $legacyIndex, $compositeIndex, up: false);
        }

        foreach ($this->swaps as [$table]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['business_id']);
                $blueprint->dropIndex(['business_id']);
                $blueprint->dropColumn('business_id');
            });
        }
    }

    private function addBusinessColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'business_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('business_id')
                ->nullable()
                ->constrained('businesses')
                ->nullOnDelete();

            $blueprint->index('business_id');
        });
    }

    private function swapUnique(string $table, string $column, string $legacyIndex, string $compositeIndex, bool $up): void
    {
        if (! Schema::hasColumn($table, 'business_id')) {
            return;
        }

        if ($up) {
            if (! Schema::hasIndex($table, $legacyIndex)) {
                return;
            }

            $ready = DB::table($table)->whereNull('business_id')->doesntExist();

            if (! $ready) {
                // Unbackfilled rows exist — keep the legacy index so
                // single-tenant installs keep their uniqueness guard.
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $legacyIndex, $compositeIndex) {
                $blueprint->dropUnique($legacyIndex);
                $blueprint->unique(['business_id', $column], $compositeIndex);
            });

            return;
        }

        if (Schema::hasIndex($table, $compositeIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $legacyIndex, $compositeIndex) {
                $blueprint->dropUnique($compositeIndex);
                $blueprint->unique($column, $legacyIndex);
            });
        }
    }
};
