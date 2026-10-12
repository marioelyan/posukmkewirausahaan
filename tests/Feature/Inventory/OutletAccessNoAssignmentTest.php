<?php

namespace Tests\Feature\Inventory;

use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutletAccessNoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(Outlet $outlet, int $sort): Warehouse
    {
        return Warehouse::create([
            'code' => $outlet->code,
            'name' => $outlet->name,
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => $sort,
            'outlet_id' => $outlet->id,
        ]);
    }

    public function test_user_without_outlets_sees_no_warehouses_in_multi_outlet_install(): void
    {
        $outletA = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $outletB = Outlet::create(['code' => 'PUT', 'name' => 'Puter']);
        $this->warehouse($outletA, 0);
        $this->warehouse($outletB, 1);

        $user = User::factory()->create();

        $this->assertTrue(app(OutletAccessService::class)->warehousesFor($user)->isEmpty());
    }

    public function test_single_outlet_install_keeps_unassigned_user_compatible(): void
    {
        $outlet = Outlet::create(['code' => 'PUSAT', 'name' => 'Pusat']);
        $warehouse = $this->warehouse($outlet, 0);

        $user = User::factory()->create();

        $this->assertSame(
            [$warehouse->id],
            app(OutletAccessService::class)->warehousesFor($user)->pluck('id')->all(),
        );
    }

    public function test_disabled_bypass_blocks_unassigned_user_even_with_single_outlet(): void
    {
        config()->set('security.outlet.legacy_single_outlet_bypass', false);

        $outlet = Outlet::create(['code' => 'PUSAT', 'name' => 'Pusat']);
        $this->warehouse($outlet, 0);

        $user = User::factory()->create();

        $this->assertTrue(app(OutletAccessService::class)->warehousesFor($user)->isEmpty());
    }

    public function test_super_admin_sees_all_warehouses(): void
    {
        $outletA = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $outletB = Outlet::create(['code' => 'PUT', 'name' => 'Puter']);
        $this->warehouse($outletA, 0);
        $this->warehouse($outletB, 1);

        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        $this->assertCount(2, app(OutletAccessService::class)->warehousesFor($admin));
    }
}
