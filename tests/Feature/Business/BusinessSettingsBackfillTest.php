<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SETTINGS-01 backfill — `business:settings-backfill`.
 *
 * Read-only by default; outlet-scoped rows inherit the outlet's business; the
 * global tenant rows are only moved when an operator explicitly targets a
 * business and confirms legacy ownership. Platform keys always stay global.
 */
class BusinessSettingsBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_writes_nothing(): void
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->makeOutlet($business, 'OA');
        $this->makeOutletScopedRow();

        $this->artisan('business:settings-backfill')
            ->expectsOutputToContain('Dry-run only')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('settings')->whereNotNull('business_id')->count());
    }

    public function test_apply_maps_outlet_rows_and_optional_target_global_rows(): void
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->makeOutlet($business, 'OA');
        $this->makeOutletScopedRow();

        // Platform key that must remain global.
        Setting::set('app_setup_completed', true);

        $this->artisan('business:settings-backfill', [
            '--apply' => true,
            '--business' => (string) $business->id,
            '--confirm-legacy-ownership' => true,
        ])->assertSuccessful();

        $this->assertSame(
            $business->id,
            (int) DB::table('settings')->where('key', 'store_name')->whereNotNull('outlet_id')->value('business_id')
        );

        // Global tenant row moved to the explicitly targeted business.
        $this->assertSame(
            $business->id,
            (int) DB::table('settings')->where('key', 'store_npwp')->whereNull('outlet_id')->value('business_id')
        );

        // Platform key untouched.
        $this->assertSame(
            1,
            DB::table('settings')->where('key', 'app_setup_completed')->whereNull('business_id')->count()
        );
    }

    public function test_apply_without_confirmation_keeps_global_rows_inherited(): void
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->makeOutlet($business, 'OA');
        $this->makeOutletScopedRow();

        $result = $this->artisan('business:settings-backfill', [
            '--apply' => true,
            '--business' => (string) $business->id,
        ]);

        $result->assertExitCode(1);

        $this->assertSame(0, DB::table('settings')->whereNotNull('business_id')->count());
    }

    private function makeOutlet(Business $business, string $code): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => 'Outlet '.$code,
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
    }

    private function makeOutletScopedRow(): void
    {
        // Written while tenancy is disabled (default in tests): outlet-scoped,
        // business_id stays null until backfill.
        $outlet = Outlet::firstOrFail();
        Setting::setForOutlet('store_name', 'Toko', $outlet);
    }
}
