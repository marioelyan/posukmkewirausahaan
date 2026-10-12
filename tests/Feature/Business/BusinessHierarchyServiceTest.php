<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\Warehouse;
use App\Services\BusinessHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BusinessHierarchyService
    {
        return app(BusinessHierarchyService::class);
    }

    public function test_outlet_membership_is_checked_against_business(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar', 'business_id' => $business->id]);

        $this->assertTrue($this->service()->outletBelongsToBusiness($outlet, $business->id));
        $this->assertFalse($this->service()->outletBelongsToBusiness($outlet, $other->id));
        $this->assertFalse($this->service()->outletBelongsToBusiness($outlet, null));
        $this->assertTrue($this->service()->outletIdBelongsToBusiness($outlet->id, $business->id));
    }

    public function test_outlet_without_business_is_not_owned_by_anyone(): void
    {
        $business = Business::factory()->create();
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);

        $this->assertFalse($this->service()->outletBelongsToBusiness($outlet, $business->id));
    }

    public function test_warehouse_ownership_falls_back_to_outlet_chain(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar', 'business_id' => $business->id]);
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id,
            'code' => 'WH-MAL',
            'name' => 'Gudang',
            'type' => 'branch',
        ]);

        $this->assertTrue($this->service()->warehouseBelongsToBusiness($warehouse->fresh(), $business->id));
        $this->assertFalse($this->service()->warehouseBelongsToBusiness($warehouse->fresh(), $other->id));
        $this->assertTrue($this->service()->warehouseIdBelongsToBusiness($warehouse->id, $business->id));
    }

    public function test_warehouse_direct_business_id_takes_precedence(): void
    {
        $business = Business::factory()->create();
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id,
            'business_id' => $business->id,
            'code' => 'WH-MAL',
            'name' => 'Gudang',
            'type' => 'branch',
        ]);

        $this->assertTrue($this->service()->warehouseBelongsToBusiness($warehouse->fresh(), $business->id));
    }
}
