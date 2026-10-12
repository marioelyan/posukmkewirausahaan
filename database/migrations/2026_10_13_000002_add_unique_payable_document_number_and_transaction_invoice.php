<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE-10 (Q4, register §13.21 resolved) — the two identifier constraints
 * that the six-table swap deliberately deferred:
 *
 *  - `payables.document_number`  -> composite `(business_id, document_number)`
 *  - `transactions.invoice`      -> global unique
 *
 * The six document tables already carried a *global unique* index, so their
 * swap was a straight replacement. payables/transactions are only index /
 * non-unique today, so adding a constraint needs a data-safety guard beyond the
 * usual null-business_id check:
 *
 *  - payables: swap only when every row is scoped (post `business:backfill`)
 *    AND no duplicate `(business_id, document_number)` pair exists yet.
 *  - transactions: add the unique only when no duplicate invoice exists yet.
 *
 * When a guard fails the migration is a no-op, keeping the current index /
 * non-unique state — exactly like the conditional swaps in
 * 2026_10_13_000001_scope_document_numbers_to_business.php. Note that MySQL
 * treats `business_id IS NULL` rows as distinct, so the composite never
 * constrains legacy unscoped rows: flag-off single-tenant behaviour (index
 * only, duplicates allowed) is unchanged.
 *
 * `transactions.invoice` is intentionally global (not per business): it is a
 * random `TRX-*` token read without a business context — PDF/print, the public
 * `/share/transactions/{invoice}` route and the customer portal all resolve it
 * with `where('invoice', ...)`. Per-business uniqueness would make those
 * lookups ambiguous, so a single global unique is the correct invariant.
 *
 * Application validation lives in PayableController (TenantUnique): once
 * tenancy is enabled a duplicate document number is rejected as a validation
 * error instead of surfacing a raw QueryException.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addPayableCompositeUniqueIfSafe();
        $this->addTransactionInvoiceUniqueIfSafe();
    }

    public function down(): void
    {
        if (Schema::hasTable('payables') && Schema::hasIndex('payables', 'payables_business_document_number_unique')) {
            Schema::table('payables', function (Blueprint $blueprint) {
                $blueprint->dropUnique('payables_business_document_number_unique');
            });
        }

        if (Schema::hasTable('transactions') && Schema::hasIndex('transactions', 'transactions_invoice_unique')) {
            Schema::table('transactions', function (Blueprint $blueprint) {
                $blueprint->dropUnique('transactions_invoice_unique');
            });
        }
    }

    private function addPayableCompositeUniqueIfSafe(): void
    {
        if (! Schema::hasTable('payables')
            || ! Schema::hasColumn('payables', 'business_id')
            || Schema::hasIndex('payables', 'payables_business_document_number_unique')) {
            return;
        }

        // Every row must be scoped first; otherwise NULL business_id rows stay
        // distinct under MySQL and the composite would silently accept dupes.
        if (DB::table('payables')->whereNull('business_id')->exists()) {
            return;
        }

        // document_number was index-only, so duplicate pairs may already exist.
        if ($this->hasDuplicatePairs('payables', ['business_id', 'document_number'])) {
            return;
        }

        Schema::table('payables', function (Blueprint $blueprint) {
            $blueprint->unique(['business_id', 'document_number'], 'payables_business_document_number_unique');
        });
    }

    private function addTransactionInvoiceUniqueIfSafe(): void
    {
        if (! Schema::hasTable('transactions')
            || ! Schema::hasColumn('transactions', 'invoice')
            || Schema::hasIndex('transactions', 'transactions_invoice_unique')) {
            return;
        }

        if ($this->hasDuplicatePairs('transactions', ['invoice'])) {
            return;
        }

        Schema::table('transactions', function (Blueprint $blueprint) {
            $blueprint->unique('invoice', 'transactions_invoice_unique');
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function hasDuplicatePairs(string $table, array $columns): bool
    {
        return DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();
    }
};
