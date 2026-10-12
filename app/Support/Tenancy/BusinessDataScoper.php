<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Shared helper that maps still-unscoped rows (`business_id IS NULL`) to a
 * business.
 *
 * The list mirrors `BusinessBackfillCommand::$businessOwned` and adds the
 * outlet/warehouse layer. It is used by the install/bootstrap path and the demo
 * seeder so there is a single definition of "business-owned tables" for the
 * convenience flows (the auditable production path stays in
 * `business:backfill`, which reports and requires confirmation).
 */
class BusinessDataScoper
{
    /**
     * Tables whose rows are owned directly by a business.
     *
     * @var array<int, string>
     */
    public const SCOPED_TABLES = [
        'outlets',
        'warehouses',
        'products',
        'categories',
        'customers',
        'suppliers',
        'units',
        'price_lists',
        'customer_segments',
        'customer_campaigns',
        'customer_vouchers',
    ];

    /**
     * Assign every unscoped row to the business.
     *
     * @return array<string, int> table => rows updated
     */
    public function assignUnscoped(int $businessId): array
    {
        $schema = DB::getSchemaBuilder();
        $counts = [];

        foreach (self::SCOPED_TABLES as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'business_id')) {
                continue;
            }

            $counts[$table] = DB::table($table)
                ->whereNull('business_id')
                ->update(['business_id' => $businessId]);
        }

        return $counts;
    }
}
