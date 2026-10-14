<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membership provisioning: `business:backfill` scopes master data, but without
 * `business_user` rows a non-super user has no verified business context and is
 * rejected as soon as the flag is enabled. This command derives memberships
 * from existing outlet access + platform role, dry-run by default.
 */
class BusinessMembershipBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function makeOutlet(Business $business, string $code): Outlet
    {
        return Outlet::create([
            'code' => $code,
            'name' => $code,
            'business_id' => $business->id,
        ]);
    }

    /**
     * @param  array<int, Outlet>  $outlets
     */
    private function makeUser(string $email, ?string $role, array $outlets = []): User
    {
        $user = User::factory()->create(['email' => $email]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        if ($outlets !== []) {
            $user->outlets()->sync(collect($outlets)->pluck('id')->all());
        }

        return $user;
    }

    public function test_dry_run_writes_nothing(): void
    {
        $business = Business::factory()->create();
        $outlet = $this->makeOutlet($business, 'MAL');
        $this->makeUser('cashier@example.test', 'cashier', [$outlet]);

        $this->artisan('business:membership-backfill')->assertSuccessful();

        $this->assertSame(0, BusinessUser::count());
    }

    public function test_apply_creates_memberships_with_mapped_roles(): void
    {
        $business = Business::factory()->create(['name' => 'Toko A']);
        $mal = $this->makeOutlet($business, 'MAL');
        $tkb = $this->makeOutlet($business, 'TKB');
        $manager = $this->makeUser('manager@example.test', 'manager', [$mal, $tkb]);
        $cashier = $this->makeUser('cashier@example.test', 'cashier', [$mal]);

        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')
            ->assertSuccessful();

        $managerMembership = BusinessUser::query()
            ->where('user_id', $manager->id)
            ->where('business_id', $business->id)
            ->first();

        $this->assertNotNull($managerMembership);
        $this->assertSame(BusinessUser::ROLE_MANAGER, $managerMembership->role);
        $this->assertSame(BusinessUser::STATUS_ACTIVE, $managerMembership->status);
        $this->assertSame($mal->id, $managerMembership->default_outlet_id);
        $this->assertNotNull($managerMembership->joined_at);

        $cashierMembership = BusinessUser::query()->where('user_id', $cashier->id)->first();
        $this->assertNotNull($cashierMembership);
        $this->assertSame(BusinessUser::ROLE_STAFF, $cashierMembership->role);
    }

    public function test_super_admin_and_unmapped_users_are_skipped(): void
    {
        $business = Business::factory()->create();
        $outlet = $this->makeOutlet($business, 'PUSAT');
        $admin = $this->makeUser('admin@example.test', 'super-admin', [$outlet]);
        $noRole = $this->makeUser('norole@example.test', null, [$outlet]);
        $staff = $this->makeUser('staff@example.test', 'cashier', [$outlet]);

        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')
            ->assertSuccessful();

        $this->assertDatabaseMissing('business_user', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('business_user', ['user_id' => $noRole->id]);
        $this->assertDatabaseHas('business_user', ['user_id' => $staff->id]);
    }

    public function test_apply_is_idempotent(): void
    {
        $business = Business::factory()->create();
        $outlet = $this->makeOutlet($business, 'MAL');
        $this->makeUser('cashier@example.test', 'cashier', [$outlet]);

        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')->assertSuccessful();
        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')->assertSuccessful();

        $this->assertSame(1, BusinessUser::count());
    }

    public function test_apply_without_confirmation_aborts_without_writing(): void
    {
        $business = Business::factory()->create();
        $outlet = $this->makeOutlet($business, 'MAL');
        $this->makeUser('cashier@example.test', 'cashier', [$outlet]);

        $this->artisan('business:membership-backfill --apply')->assertFailed();

        $this->assertSame(0, BusinessUser::count());
    }

    public function test_no_business_is_a_noop(): void
    {
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'MAL']);
        $this->makeUser('cashier@example.test', 'cashier', [$outlet]);

        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')
            ->assertSuccessful();

        $this->assertSame(0, BusinessUser::count());
    }

    public function test_targets_a_single_business(): void
    {
        $businessA = Business::factory()->create(['name' => 'A']);
        $businessB = Business::factory()->create(['name' => 'B']);
        $outletA = $this->makeOutlet($businessA, 'OA');
        $outletB = $this->makeOutlet($businessB, 'OB');
        $userA = $this->makeUser('a@example.test', 'cashier', [$outletA]);
        $userB = $this->makeUser('b@example.test', 'cashier', [$outletB]);

        $this->artisan("business:membership-backfill --business={$businessA->id} --apply --confirm-membership-backfill")
            ->assertSuccessful();

        $this->assertDatabaseHas('business_user', ['business_id' => $businessA->id, 'user_id' => $userA->id]);
        $this->assertDatabaseMissing('business_user', ['business_id' => $businessB->id, 'user_id' => $userB->id]);
    }

    public function test_user_spanning_two_businesses_gets_one_membership_each(): void
    {
        $businessA = Business::factory()->create(['name' => 'A']);
        $businessB = Business::factory()->create(['name' => 'B']);
        $outletA = $this->makeOutlet($businessA, 'OA');
        $outletB = $this->makeOutlet($businessB, 'OB');
        $user = $this->makeUser('multi@example.test', 'manager', [$outletA, $outletB]);

        $this->artisan('business:membership-backfill --apply --confirm-membership-backfill')
            ->assertSuccessful();

        $this->assertSame(2, BusinessUser::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('business_user', ['business_id' => $businessA->id, 'user_id' => $user->id, 'role' => BusinessUser::ROLE_MANAGER]);
        $this->assertDatabaseHas('business_user', ['business_id' => $businessB->id, 'user_id' => $user->id, 'role' => BusinessUser::ROLE_MANAGER]);
    }
}
