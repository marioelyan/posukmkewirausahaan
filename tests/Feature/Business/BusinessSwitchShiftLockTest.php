<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C5: switching the active business is locked while the user has an open shift
 * that belongs to a different business — mirroring the outlet-switch lock.
 * Re-selecting the business that owns the open shift stays allowed (no-op).
 */
class BusinessSwitchShiftLockTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        config()->set('multi_business.enabled', true);

        $this->businessA = Business::create([
            'name' => 'Toko Alfa',
            'slug' => 'toko-alfa',
            'status' => Business::STATUS_ACTIVE,
        ]);

        $this->businessB = Business::create([
            'name' => 'Toko Beta',
            'slug' => 'toko-beta',
            'status' => Business::STATUS_ACTIVE,
        ]);

        $this->manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        foreach ([$this->businessA, $this->businessB] as $business) {
            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $this->manager->id,
                'role' => BusinessUser::ROLE_MANAGER,
                'status' => BusinessUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }
    }

    private function openShiftInBusinessA(): void
    {
        $outlet = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OUT-A',
            'name' => 'Outlet Alfa',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::create([
            'business_id' => $this->businessA->id,
            'outlet_id' => $outlet->id,
            'code' => 'WH-A',
            'name' => 'Gudang Alfa',
            'type' => 'branch',
            'is_active' => true,
        ]);

        CashierShift::create([
            'user_id' => $this->manager->id,
            'opened_by' => $this->manager->id,
            'warehouse_id' => $warehouse->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    public function test_cannot_switch_business_while_shift_open_in_another_business(): void
    {
        $this->openShiftInBusinessA();

        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('business.switch'), ['business_id' => $this->businessB->id])
            ->assertStatus(422);
    }

    public function test_can_switch_to_the_business_owning_the_open_shift(): void
    {
        $this->openShiftInBusinessA();

        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('business.switch'), ['business_id' => $this->businessA->id])
            ->assertRedirect();
    }

    public function test_can_switch_business_without_open_shift(): void
    {
        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('business.switch'), ['business_id' => $this->businessB->id])
            ->assertRedirect();

        $this->assertSame($this->businessB->id, (int) session('active_business_id'));
    }
}
