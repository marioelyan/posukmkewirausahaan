<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Support\Tenancy\BusinessRoleCapabilities;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AUTH-01/02/03 — per-business role capability enforcement.
 *
 * Spatie runs with `teams=false`, so a permission earned in one business
 * (via a global role) could otherwise be exercised inside another business
 * where the user holds a lower membership role. The `business.role`
 * middleware closes that gap when `multi_business.enabled` is true: the
 * active membership's business role defines the effective capability set.
 * Super-admins keep the explicit platform path (AUTH-01); flag off preserves
 * legacy global authorisation byte-for-byte.
 */
class AuthzBusinessRoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $flexibleManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->businessA = Business::factory()->create(['name' => 'Business A']);
        $this->businessB = Business::factory()->create(['name' => 'Business B']);

        // A user with a GLOBAL manager role (platform-wide capability), but a
        // different membership role per business: manager in A, staff in B.
        $this->flexibleManager = User::factory()->create(['name' => 'Flexible Manager']);
        $this->flexibleManager->assignRole('manager');

        $this->makeMembership($this->businessA, $this->flexibleManager, BusinessUser::ROLE_MANAGER);
        $this->makeMembership($this->businessB, $this->flexibleManager, BusinessUser::ROLE_STAFF);
    }

    public function test_dashboard_route_is_denied_when_business_role_does_not_grant_permission(): void
    {
        config()->set('multi_business.enabled', true);

        // Business A: membership role "manager" grants products-access.
        $this->actingAs($this->flexibleManager)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get('/dashboard/products')
            ->assertOk();

        // Business B: membership role "staff" does NOT grant products-access,
        // even though the user holds the global manager role.
        $this->actingAs($this->flexibleManager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get('/dashboard/products')
            ->assertForbidden();
    }

    public function test_api_route_is_denied_when_business_role_does_not_grant_ability(): void
    {
        config()->set('multi_business.enabled', true);

        Sanctum::actingAs($this->flexibleManager, ['products-access']);

        // Business A (manager role) → allowed.
        $this->get('/api/v1/products', ['X-Business-Id' => $this->businessA->id])
            ->assertOk();

        // Business B (staff role) → the token carries the global ability, but
        // the membership role does not grant it for that business.
        $this->get('/api/v1/products', ['X-Business-Id' => $this->businessB->id])
            ->assertForbidden();
    }

    public function test_super_admin_bypasses_membership_role_scoping(): void
    {
        config()->set('multi_business.enabled', true);

        $superAdmin = User::factory()->create(['name' => 'Super Admin']);
        $superAdmin->assignRole('super-admin');

        // Super-admin has NO membership in business B; the platform path
        // (AUTH-01) still authorises the request.
        $this->actingAs($superAdmin)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get('/dashboard/products')
            ->assertOk();
    }

    public function test_flag_off_keeps_legacy_global_authorization(): void
    {
        config()->set('multi_business.enabled', false);

        // Legacy mode: no business context resolution, no role scoping. The
        // global manager role authorises the route regardless of membership.
        $this->actingAs($this->flexibleManager)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get('/dashboard/products')
            ->assertOk();
    }

    public function test_role_capability_mapping_matches_platform_roles(): void
    {
        $capabilities = new BusinessRoleCapabilities;

        // staff mirrors the cashier role: POS + read-mostly, no products admin.
        $staffPerms = $capabilities->permissionsFor(BusinessUser::ROLE_STAFF);
        $this->assertContains('pos-access', $staffPerms);
        $this->assertContains('payables-pay', $staffPerms);
        $this->assertNotContains('products-access', $staffPerms);

        // manager mirrors the manager role: full operational scope, no admin.
        $managerPerms = $capabilities->permissionsFor(BusinessUser::ROLE_MANAGER);
        $this->assertContains('products-access', $managerPerms);
        $this->assertContains('payables-access', $managerPerms);
        $this->assertNotContains('users-access', $managerPerms);
        $this->assertNotContains('payment-settings-update', $managerPerms);

        // owner: everything except cross-business platform administration.
        $ownerPerms = $capabilities->permissionsFor(BusinessUser::ROLE_OWNER);
        $this->assertContains('outlets-access', $ownerPerms);
        $this->assertContains('payment-settings-update', $ownerPerms);
        $this->assertNotContains('users-access', $ownerPerms);
        $this->assertNotContains('roles-access', $ownerPerms);
        $this->assertNotContains('permissions-access', $ownerPerms);
        $this->assertNotContains('financial-ownership-reconcile', $ownerPerms);

        // Unknown roles grant nothing (defensive boundary).
        $this->assertSame([], $capabilities->permissionsFor('unknown-role'));
    }

    private function makeMembership(Business $business, User $user, string $role): void
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
