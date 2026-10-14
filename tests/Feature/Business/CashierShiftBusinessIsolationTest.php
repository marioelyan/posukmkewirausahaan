<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An open cashier shift belongs to the business that owns its warehouse.
 * `cashier_shifts.business_id` now records that ownership explicitly, so the
 * model's `BelongsToBusiness` global scope limits every read to the active
 * business. Before the column existed a plain `CashierShift::query()` was
 * globally unscoped: it handed the shared `activeCashierShift` prop (and the
 * outlet lock derived from it) to a request whose active business was a
 * *different* one — the reported "shift aktif di UKM Kewirausahaan ikut aktif
 * di Warung Seblak" leak.
 *
 * These tests pin the boundary on the read/share paths. The gate paths
 * (`active_shift` middleware, checkout) already go through the service query,
 * and the switcher lock is covered by BusinessSwitchShiftLockTest.
 */
class CashierShiftBusinessIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $manager;

    private Outlet $outletA;

    private Outlet $outletB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        config()->set('multi_business.enabled', true);

        $this->businessA = Business::factory()->create(['name' => 'UKM Kewirausahaan', 'status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['name' => 'Warung Seblak', 'status' => Business::STATUS_ACTIVE]);

        $this->outletA = $this->outlet($this->businessA, 'OUT-A', 'Outlet UKM');
        $this->outletB = $this->outlet($this->businessB, 'OUT-B', 'Outlet Seblak');

        $this->warehouseA = $this->warehouse($this->businessA, $this->outletA, 'WH-A', 'Gudang UKM');
        $this->warehouseB = $this->warehouse($this->businessB, $this->outletB, 'WH-B', 'Gudang Seblak');

        $this->manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->manager->markEmailAsVerified();

        $this->member($this->manager, $this->businessA, BusinessUser::ROLE_MANAGER);
        $this->member($this->manager, $this->businessB, BusinessUser::ROLE_MANAGER);

        // Context is clear while the fixtures are built, so the ownership is
        // exactly what is passed here and not something derived from a request.
        app(BusinessContext::class)->clear();
    }

    private function outlet(Business $business, string $code, string $name): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
    }

    private function warehouse(Business $business, Outlet $outlet, string $code, string $name): Warehouse
    {
        return Warehouse::create([
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => $name,
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function member(User $user, Business $business, string $role): void
    {
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    private function openShift(User $user, Warehouse $warehouse): CashierShift
    {
        return CashierShift::create([
            'user_id' => $user->id,
            'business_id' => $warehouse->business_id,
            'opened_by' => $user->id,
            'warehouse_id' => $warehouse->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    public function test_open_shift_in_another_business_is_not_shared_as_active(): void
    {
        $shift = $this->openShift($this->manager, $this->warehouseA);

        // Active business is Warung Seblak, but the open shift lives in UKM.
        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCashierShift', null)
                // The outlet switcher must not be locked by a foreign shift.
                ->where('auth.outletLocked', false)
            );

        // Guard against the assertion above passing by simply not finding it.
        $this->assertDatabaseHas('cashier_shifts', ['id' => $shift->id, 'warehouse_id' => $this->warehouseA->id]);
    }

    public function test_open_shift_is_shared_as_active_in_the_business_that_owns_it(): void
    {
        $shift = $this->openShift($this->manager, $this->warehouseA);

        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCashierShift.id', $shift->id)
                ->where('auth.outletLocked', true)
                ->where('auth.currentOutlet.id', $this->outletA->id)
            );
    }

    public function test_pos_page_does_not_report_a_foreign_shift_as_active(): void
    {
        $this->openShift($this->manager, $this->warehouseA);

        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCashierShift', null)
            );
    }

    public function test_cashier_shift_history_is_scoped_to_the_active_business(): void
    {
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $cashier->markEmailAsVerified();
        $this->member($cashier, $this->businessA, BusinessUser::ROLE_STAFF);
        $this->member($cashier, $this->businessB, BusinessUser::ROLE_STAFF);

        $this->openShift($cashier, $this->warehouseA);
        $shiftB = $this->openShift($cashier, $this->warehouseB);

        $this->actingAs($cashier)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('cashier-shifts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shifts.data', 1)
                ->where('shifts.data.0.id', $shiftB->id)
                ->where('activeShift.id', $shiftB->id)
            );
    }

    public function test_business_switcher_is_locked_while_a_shift_is_open(): void
    {
        $this->openShift($this->manager, $this->warehouseA);

        // Even from the *other* business the switcher must read as locked: the
        // open shift means the operator is on duty, and every switch except the
        // shift's own business is rejected server-side.
        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.businessSwitchLocked', true)
            );
    }

    public function test_business_switcher_is_unlocked_without_an_open_shift(): void
    {
        $this->actingAs($this->manager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.businessSwitchLocked', false)
            );
    }

    public function test_shift_isolation_is_inert_when_tenancy_is_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $shift = $this->openShift($this->manager, $this->warehouseA);

        // Without tenancy the legacy single-tenant view is unchanged: the shift
        // is still shown as active.
        $this->actingAs($this->manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCashierShift.id', $shift->id)
            );
    }
}
