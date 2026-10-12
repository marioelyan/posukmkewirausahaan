<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the Phase 8-lite UI plumbing: super-admin business fallback,
 * the business switcher route, and the shared props. Everything here is
 * gated on multi_business.enabled — with the flag OFF the route is inert
 * and no extra props are shared (production/legacy payload unchanged).
 */
class BusinessTenancyUiDemoTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

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
    }

    private function admin(): User
    {
        $user = User::where('email', 'arya@gmail.com')->firstOrFail();

        return $user;
    }

    public function test_super_admin_auto_resolves_to_first_active_business(): void
    {
        $admin = $this->admin();

        // The resolved context only lives for the duration of the request
        // (BusinessContext is scoped and cleared on RequestHandled), so assert
        // it through the Inertia shared props instead of after the response.
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.currentBusiness.id', $this->businessA->id)
            );
    }

    public function test_business_switch_updates_session_and_context(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('business.switch'), ['business_id' => $this->businessB->id])
            ->assertRedirect();

        $this->assertSame($this->businessB->id, (int) session('active_business_id'));

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.currentBusiness.id', $this->businessB->id)
            );
    }

    public function test_non_super_admin_may_only_switch_to_membership_business(): void
    {
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $manager->markEmailAsVerified();

        BusinessUser::create([
            'business_id' => $this->businessB->id,
            'user_id' => $manager->id,
            'role' => BusinessUser::ROLE_MANAGER,
            'status' => BusinessUser::STATUS_ACTIVE,
        ]);

        $this->actingAs($manager)
            ->post(route('business.switch'), ['business_id' => $this->businessA->id])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post(route('business.switch'), ['business_id' => $this->businessB->id])
            ->assertRedirect();

        $this->assertSame($this->businessB->id, (int) session('active_business_id'));
    }

    public function test_switch_route_is_inert_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $this->actingAs($this->admin())
            ->post(route('business.switch'), ['business_id' => $this->businessA->id])
            ->assertNotFound();
    }

    public function test_shared_props_carry_business_switcher_data_when_enabled(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.businesses', 2)
                ->where('auth.currentBusiness.id', $this->businessA->id)
            );
    }

    public function test_payables_page_renders_under_active_business_context(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('payables.index'))
            ->assertOk();
    }

    public function test_shared_permissions_are_scoped_to_the_membership_role(): void
    {
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->member($manager, $this->businessA, BusinessUser::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.products-access', true)
                ->missing('auth.permissions.users-access')
                ->missing('auth.permissions.payment-settings-update')
            );
    }

    public function test_shared_permissions_follow_membership_role_per_business(): void
    {
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->member($manager, $this->businessA, BusinessUser::ROLE_MANAGER);
        $this->member($manager, $this->businessB, BusinessUser::ROLE_STAFF);

        // Manager in A: products administration is visible.
        $this->actingAs($manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.products-access', true)
            );

        // Staff in B: POS is visible, products administration is hidden.
        $this->actingAs($manager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.pos-access', true)
                ->missing('auth.permissions.products-access')
            );
    }

    public function test_shared_permissions_stay_global_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        // Membership is ignored while the flag is off; the legacy global map wins.
        $this->member($manager, $this->businessA, BusinessUser::ROLE_STAFF);

        $this->actingAs($manager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions.products-access', true)
            );
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
}
