<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Seeds the spec §2.1 chart of accounts.
 *
 * Idempotent: safe to re-run on deploy. One full chart per business, plus
 * one null-business (legacy/global) chart used when a source document has
 * no provable business. 3200 Laba Tahun Berjalan is NOT seeded: it is a
 * computed balance-sheet line, not a posted account.
 */
class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $businessIds = Business::pluck('id')->all();
        $businessIds[] = null;

        foreach ($businessIds as $businessId) {
            foreach (Account::CHART as $code => [$name, $category]) {
                Account::firstOrCreate(
                    ['business_id' => $businessId, 'code' => $code],
                    ['name' => $name, 'category' => $category, 'is_active' => true],
                );
            }
        }
    }
}
