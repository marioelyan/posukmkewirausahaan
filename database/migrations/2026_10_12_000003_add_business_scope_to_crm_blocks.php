<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE-08/09 (Q4, decision B — per-business) + CRM ownership.
 *
 * Adds the missing `business_id` column to `customer_vouchers` (UNIQUE-08),
 * `customer_segments` (UNIQUE-09) and `customer_campaigns` (ownership only),
 * then swaps the global unique identifiers the same way the earlier
 * master-data migration (2026_10_12_000002) did:
 *
 *  - `customer_vouchers.code`  -> composite `(business_id, code)`
 *  - `customer_segments.slug`  -> composite `(business_id, slug)`
 *
 * Both swaps are guarded: a composite unique treats `business_id IS NULL` rows
 * as distinct (MySQL), so the swap only happens while zero rows are unscoped —
 * exactly what `business:backfill` guarantees. Unbackfilled installs keep the
 * legacy global index untouched. `customer_campaigns.context_key` stays a
 * global unique (UNIQUE-11: candidate waits for separate evidence/approval);
 * the campaign only gains business ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        // UNIQUE-09: customer_segments (business_id, slug)
        $this->addBusinessColumn('customer_segments');
        $this->swapUnique(
            'customer_segments',
            'slug',
            'customer_segments_slug_unique',
            'customer_segments_business_slug_unique',
            up: true,
        );

        // customer_campaigns: ownership column only.
        $this->addBusinessColumn('customer_campaigns');

        // UNIQUE-08: customer_vouchers (business_id, code)
        $this->addBusinessColumn('customer_vouchers');
        $this->swapUnique(
            'customer_vouchers',
            'code',
            'customer_vouchers_code_unique',
            'customer_vouchers_business_code_unique',
            up: true,
        );
    }

    public function down(): void
    {
        $this->swapUnique(
            'customer_segments',
            'slug',
            'customer_segments_slug_unique',
            'customer_segments_business_slug_unique',
            up: false,
        );
        $this->swapUnique(
            'customer_vouchers',
            'code',
            'customer_vouchers_code_unique',
            'customer_vouchers_business_code_unique',
            up: false,
        );

        foreach (['customer_segments', 'customer_campaigns', 'customer_vouchers'] as $table) {
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
                // Unbackfilled rows exist — keep the legacy index so single-tenant
                // installs do not lose their database-level uniqueness guard.
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($legacyIndex, $column, $compositeIndex) {
                $blueprint->dropUnique($legacyIndex);
                $blueprint->unique(['business_id', $column], $compositeIndex);
            });

            return;
        }

        if (Schema::hasIndex($table, $compositeIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($legacyIndex, $column, $compositeIndex) {
                $blueprint->dropUnique($compositeIndex);
                $blueprint->unique($column, $legacyIndex);
            });
        }
    }
};
