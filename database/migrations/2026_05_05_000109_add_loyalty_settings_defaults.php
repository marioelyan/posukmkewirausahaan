<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            ['key' => 'loyalty_enable_earn', 'value' => '1', 'description' => 'Aktifkan perolehan poin loyalty'],
            ['key' => 'loyalty_enable_redeem', 'value' => '1', 'description' => 'Aktifkan redeem poin loyalty'],
            ['key' => 'loyalty_earn_rate_amount', 'value' => '10000', 'description' => 'Nominal belanja untuk mendapatkan 1 poin'],
            ['key' => 'loyalty_redeem_point_value', 'value' => '100', 'description' => 'Nilai rupiah untuk 1 poin redeem'],
            ['key' => 'loyalty_tier_regular_threshold', 'value' => '0', 'description' => 'Ambang total belanja tier Regular'],
            ['key' => 'loyalty_tier_silver_threshold', 'value' => '500000', 'description' => 'Ambang total belanja tier Silver'],
            ['key' => 'loyalty_tier_gold_threshold', 'value' => '1500000', 'description' => 'Ambang total belanja tier Gold'],
            ['key' => 'loyalty_tier_platinum_threshold', 'value' => '3000000', 'description' => 'Ambang total belanja tier Platinum'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'value' => $row['value'],
                    'description' => $row['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', [
                'loyalty_enable_earn',
                'loyalty_enable_redeem',
                'loyalty_earn_rate_amount',
                'loyalty_redeem_point_value',
                'loyalty_tier_regular_threshold',
                'loyalty_tier_silver_threshold',
                'loyalty_tier_gold_threshold',
                'loyalty_tier_platinum_threshold',
            ])
            ->delete();
    }
};
