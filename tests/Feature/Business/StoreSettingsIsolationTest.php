<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Store settings isolation (ADR-04 / Q5).
 *
 * The outlet-scoped store profile (store_name etc.) is resolved through the
 * active outlet, which `OutletAccessService` restricts to the verified active
 * business. This test locks that isolated path: business A's profile must not
 * be visible while business B is active and vice versa.
 *
 * Tenant policy settings (store_npwp, store_nib, tax_default_rate,
 * discount_approval_*, loyalty_*) are now business-scoped via
 * `settings.business_id` (SETTINGS-01..07), with the legacy global row as the
 * inherited base layer.
 */
class StoreSettingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_profile_is_isolated_per_active_business(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $outletA = Outlet::create([
            'business_id' => $businessA->id,
            'code' => 'OA',
            'name' => 'Outlet A',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $outletB = Outlet::create([
            'business_id' => $businessB->id,
            'code' => 'OB',
            'name' => 'Outlet B',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        Setting::setForOutlet('store_name', 'Toko A', $outletA);
        Setting::setForOutlet('store_name', 'Toko B', $outletB);

        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('storeProfile.name', 'Toko A'));

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessB->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('storeProfile.name', 'Toko B'));
    }

    public function test_business_policy_settings_are_isolated_per_active_business(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $context = app(BusinessContext::class);

        $context->setActive($businessA);
        Setting::set('store_npwp', 'NPWP-A');
        Setting::set('tax_default_rate', '11.00');

        $context->setActive($businessB);
        Setting::set('store_npwp', 'NPWP-B');
        Setting::set('tax_default_rate', '12.00');

        $context->setActive($businessA);
        $this->assertSame('NPWP-A', Setting::get('store_npwp'));
        $this->assertSame('11.00', Setting::get('tax_default_rate'));

        $context->setActive($businessB);
        $this->assertSame('NPWP-B', Setting::get('store_npwp'));
        $this->assertSame('12.00', Setting::get('tax_default_rate'));
    }
}
