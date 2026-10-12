<?php

namespace Tests\Feature\Business;

use App\Exceptions\TenantSettingScopeException;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\Setting;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SETTINGS-01..07 (Q5 / ADR-04 option C).
 *
 * The settings table now has a business dimension. Resolution is
 * `outlet -> business -> global`; platform keys (app_setup_completed,
 * setup_warehouse_id) keep using the single global row. Tenant-owned writes
 * require an active business and are otherwise rejected (fail closed), while
 * reads inherit the global row as the base layer. Flag off = legacy mode.
 */
class SettingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

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

    private function activeBusiness(): Business
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        app(BusinessContext::class)->setActive($business);

        return $business;
    }

    public function test_tenant_setting_is_scoped_per_business_when_tenancy_enabled(): void
    {
        // A legacy global row written while tenancy was disabled (shared row).
        Setting::set('store_npwp', 'LEGACY-NPWP');

        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        Setting::set('store_npwp', 'NPWP-A');

        $this->assertDatabaseHas('settings', [
            'key' => 'store_npwp',
            'business_id' => $businessA->id,
            'value' => 'NPWP-A',
        ]);
        $this->assertSame('NPWP-A', Setting::get('store_npwp'));

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();

        // Business B has no override yet: it inherits the global default and
        // never sees business A's value.
        $this->assertSame('LEGACY-NPWP', Setting::get('store_npwp'));

        Setting::set('store_npwp', 'NPWP-B');
        $this->assertSame('NPWP-B', Setting::get('store_npwp'));

        app(BusinessContext::class)->clear();
        app(BusinessContext::class)->setActive($businessA);
        $this->assertSame('NPWP-A', Setting::get('store_npwp'));

        // The shared global row is untouched by either business.
        $this->assertSame(
            'LEGACY-NPWP',
            Setting::where('key', 'store_npwp')->whereNull('business_id')->whereNull('outlet_id')->value('value')
        );
        $this->assertSame(1, Setting::where('key', 'store_npwp')->whereNull('business_id')->whereNull('outlet_id')->count());
    }

    public function test_tenant_setting_write_without_active_business_is_rejected(): void
    {
        config()->set('multi_business.enabled', true);
        app(BusinessContext::class)->clear();

        $before = Setting::where('key', 'store_npwp')->whereNull('business_id')->value('value');

        $this->expectException(TenantSettingScopeException::class);

        try {
            Setting::set('store_npwp', 'NPWP-A');
        } finally {
            $this->assertSame(
                $before,
                Setting::where('key', 'store_npwp')->whereNull('business_id')->whereNull('outlet_id')->value('value')
            );
        }
    }

    public function test_platform_global_settings_still_work_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);
        $this->activeBusiness();

        Setting::set('app_setup_completed', true);
        Setting::set('setup_warehouse_id', 42);

        $this->assertTrue(Setting::getBool('app_setup_completed'));
        $this->assertSame('42', (string) Setting::get('setup_warehouse_id'));
        $this->assertDatabaseHas('settings', [
            'key' => 'app_setup_completed',
            'business_id' => null,
            'outlet_id' => null,
        ]);
    }

    public function test_outlet_setting_resolves_outlet_then_business_then_global(): void
    {
        Setting::set('store_name', 'GLOBAL-NAME');

        config()->set('multi_business.enabled', true);
        $business = $this->activeBusiness();
        Setting::set('store_name', 'BIZ-NAME');

        $outletOverride = $this->makeOutlet($business, 'OA');
        $outletInherit = $this->makeOutlet($business, 'OB');

        Setting::setForOutlet('store_name', 'OUTLET-NAME', $outletOverride);

        // Outlet layer wins where set.
        $this->assertSame('OUTLET-NAME', Setting::getForOutlet('store_name', $outletOverride));
        // Otherwise the business layer wins.
        $this->assertSame('BIZ-NAME', Setting::getForOutlet('store_name', $outletInherit));
        $this->assertSame('BIZ-NAME', Setting::get('store_name'));

        // A different business with no override inherits the global base row.
        app(BusinessContext::class)->clear();
        $businessC = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $outletC = $this->makeOutlet($businessC, 'OC');
        $this->assertSame('GLOBAL-NAME', Setting::getForOutlet('store_name', $outletC));
    }

    public function test_set_for_outlet_without_outlet_writes_business_default_under_tenancy(): void
    {
        Setting::set('store_name', 'GLOBAL-NAME');

        config()->set('multi_business.enabled', true);
        $business = $this->activeBusiness();

        // With an active business this becomes a business-level default, never
        // the global row.
        Setting::setForOutlet('store_name', 'BIZ-NAME', null);

        $this->assertDatabaseHas('settings', [
            'key' => 'store_name',
            'business_id' => $business->id,
            'outlet_id' => null,
            'value' => 'BIZ-NAME',
        ]);
        $this->assertSame('GLOBAL-NAME', Setting::where('key', 'store_name')->whereNull('business_id')->whereNull('outlet_id')->value('value'));
    }

    public function test_global_setting_behaviour_is_unchanged_when_tenancy_disabled(): void
    {
        $this->assertFalse((bool) config('multi_business.enabled'));

        Setting::set('store_npwp', 'LEGACY-NPWP');

        $this->assertSame('LEGACY-NPWP', Setting::get('store_npwp'));
        $this->assertDatabaseHas('settings', [
            'key' => 'store_npwp',
            'business_id' => null,
            'outlet_id' => null,
        ]);
    }
}
