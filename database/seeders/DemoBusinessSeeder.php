<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Setting;
use App\Support\Tenancy\BusinessBootstrap;
use App\Support\Tenancy\BusinessMembershipProvisioner;
use Illuminate\Database\Seeder;

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
    public function run(): void
    {
        $business = Business::query()->orderBy('id')->first();

        if (! $business) {
            $business = app(BusinessBootstrap::class)->bootstrap(
                Setting::get('store_name') ?: (string) config('multi_business.default_name'),
            );

            $this->command?->info("Demo business created: #{$business->id} {$business->name}");
        } else {
            app(BusinessBootstrap::class)->bootstrap($business->name);
        }

        $result = app(BusinessMembershipProvisioner::class)->apply();

        $this->command?->info(
            "Demo memberships: {$result['created']} created, {$result['skipped']} already present, {$result['unmapped']} unmapped."
        );
    }
}
