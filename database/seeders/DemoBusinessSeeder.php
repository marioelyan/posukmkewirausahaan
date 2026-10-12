<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Setting;
use App\Support\Tenancy\BusinessMembershipProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes the demo dataset usable with `MULTI_BUSINESS_ENABLED=true`.
 *
 * `business:backfill` is the sanctioned production tool, but the demo seeder
 * never created a business at all, so a demo install with the flag on had no
 * context (every non-super user was rejected) and its master data was
 * unscoped. This seeder closes that gap for the demo only:
 *
 *  1. create the demo business when none exists (idempotent);
 *  2. map every still-unscoped outlet, warehouse and master-data row to it;
 *  3. provision memberships from the demo users' outlet assignments.
 *
 * It is demo-scoped and never runs on production (DemoSeeder is opt-in).
 */
class DemoBusinessSeeder extends Seeder
{
    /**
     * Master-data tables owned directly by a business (mirrors
     * BusinessBackfillCommand::$businessOwned).
     *
     * @var array<int, string>
     */
    private array $businessOwned = [
        'products',
        'categories',
        'customers',
        'suppliers',
        'units',
        'price_lists',
    ];

    public function run(): void
    {
        $business = Business::query()->orderBy('id')->first();

        if (! $business) {
            $name = Setting::get('store_name') ?: config('multi_business.default_name');

            $business = Business::create([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'status' => Business::STATUS_ACTIVE,
                'currency' => 'IDR',
                'timezone' => config('app.timezone', 'Asia/Jakarta'),
            ]);

            $this->command?->info("Demo business created: #{$business->id} {$business->name}");
        }

        $this->assignUnscoped($business->id);

        $result = app(BusinessMembershipProvisioner::class)->apply();

        $this->command?->info(
            "Demo memberships: {$result['created']} created, {$result['skipped']} already present, {$result['unmapped']} unmapped."
        );
    }

    private function assignUnscoped(int $businessId): void
    {
        foreach (array_merge(['outlets', 'warehouses'], $this->businessOwned) as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table) || ! DB::getSchemaBuilder()->hasColumn($table, 'business_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('business_id')
                ->update(['business_id' => $businessId]);
        }
    }
}
