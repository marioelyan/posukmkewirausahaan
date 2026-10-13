<?php

namespace Tests\Feature\Business;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use App\Services\BusinessMembershipResolver;
use App\Support\Tenancy\BusinessProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C1 — platform-admin business membership management.
 *
 * Memberships (role/status) and outlet access (`user_outlets`) are managed
 * together so a member is functional straight away. Super-admins are never
 * members, the business owner membership is protected, and all writes require a
 * fresh password confirmation.
 */
class BusinessMembershipAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->superAdmin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
    }

    private function provision(string $name, ?int $ownerId = null): Business
    {
        return app(BusinessProvisioner::class)->provision($name, $ownerId ? User::find($ownerId) : null);
    }

    private function branch(Business $business, string $code = 'BR1'): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => 'Cabang '.$code,
            'is_active' => true,
        ]);
    }

    private function addMember(Business $business, array $payload)
    {
        return $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('businesses.members.store', $business->id), $payload);
    }

    private function member(Business $business, User $user): BusinessUser
    {
        return BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // Access boundary
    // ---------------------------------------------------------------------

    public function test_platform_admin_can_view_members(): void
    {
        $business = $this->provision('Toko Alfa', $this->manager->id);
        $this->branch($business);

        $this->actingAs($this->superAdmin)
            ->get(route('businesses.show', $business->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Businesses/Show')
                ->where('business.id', $business->id)
                ->has('members', 1)
                ->where('members.0.user.id', $this->manager->id)
                ->where('members.0.role', BusinessUser::ROLE_OWNER)
                ->where('members.0.is_owner', true)
                ->has('outlets', 2)
                ->has('candidateUsers', 1)
            );
    }

    public function test_non_platform_admin_cannot_view_members(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->actingAs($this->manager)
            ->get(route('businesses.show', $business->id))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Add member + outlet access
    // ---------------------------------------------------------------------

    public function test_platform_admin_can_add_member_with_outlet_access(): void
    {
        $business = $this->provision('Toko Alfa');
        $pusat = $business->outlets()->where('code', 'PUSAT')->firstOrFail();
        $branch = $this->branch($business);

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
            'status' => BusinessUser::STATUS_ACTIVE,
            'outlet_ids' => [$pusat->id, $branch->id],
            'default_outlet_id' => $branch->id,
        ])->assertRedirect(route('businesses.show', $business->id));

        $member = $this->member($business, $this->cashier);
        $this->assertSame(BusinessUser::ROLE_STAFF, $member->role);
        $this->assertSame(BusinessUser::STATUS_ACTIVE, $member->status);
        $this->assertSame($branch->id, (int) $member->default_outlet_id);
        $this->assertSame($this->superAdmin->id, (int) $member->invited_by_user_id);

        $attached = $this->cashier->fresh()->outlets()->pluck('outlets.id')
            ->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$pusat->id, $branch->id], $attached);

        $this->assertTrue((bool) DB::table('user_outlets')
            ->where('user_id', $this->cashier->id)
            ->where('outlet_id', $branch->id)
            ->value('is_default'));

        $this->assertSame(1, AuditLog::where('event', 'business.member.added')->count());
    }

    public function test_super_admin_cannot_be_added_as_member(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->addMember($business, [
            'user_id' => $this->superAdmin->id,
            'role' => BusinessUser::ROLE_STAFF,
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(0, BusinessUser::where('business_id', $business->id)->count());
    }

    public function test_duplicate_member_is_rejected(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
        ])->assertRedirect();

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_MANAGER,
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(1, BusinessUser::where('business_id', $business->id)->count());
    }

    public function test_invalid_role_is_rejected(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => 'boss',
        ])->assertSessionHasErrors('role');
    }

    public function test_outlet_from_another_business_is_rejected(): void
    {
        $business = $this->provision('Toko Alfa');
        $other = $this->provision('Toko Beta');
        $foreignOutlet = $other->outlets()->where('code', 'PUSAT')->firstOrFail();

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
            'outlet_ids' => [$foreignOutlet->id],
            'default_outlet_id' => $foreignOutlet->id,
        ])->assertSessionHasErrors('outlet_ids');

        $this->assertSame(0, BusinessUser::where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------------
    // Update / suspend
    // ---------------------------------------------------------------------

    public function test_platform_admin_can_update_member_role_and_status(): void
    {
        $business = $this->provision('Toko Alfa');
        $pusat = $business->outlets()->where('code', 'PUSAT')->firstOrFail();
        $branch = $this->branch($business);

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
            'outlet_ids' => [$pusat->id, $branch->id],
            'default_outlet_id' => $branch->id,
        ])->assertRedirect();

        $member = $this->member($business, $this->cashier);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.members.update', [$business->id, $member->id]), [
                'role' => BusinessUser::ROLE_MANAGER,
                'status' => BusinessUser::STATUS_ACTIVE,
                'outlet_ids' => [$pusat->id],
                'default_outlet_id' => $pusat->id,
            ])
            ->assertRedirect(route('businesses.show', $business->id));

        $member->refresh();
        $this->assertSame(BusinessUser::ROLE_MANAGER, $member->role);
        $this->assertSame($pusat->id, (int) $member->default_outlet_id);

        $attached = $this->cashier->fresh()->outlets()->pluck('outlets.id')
            ->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$pusat->id], $attached);

        $this->assertSame(1, AuditLog::where('event', 'business.member.updated')->count());
    }

    public function test_suspended_member_is_not_an_active_member(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
        ])->assertRedirect();

        $member = $this->member($business, $this->cashier);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.members.update', [$business->id, $member->id]), [
                'role' => BusinessUser::ROLE_STAFF,
                'status' => BusinessUser::STATUS_SUSPENDED,
            ])
            ->assertRedirect();

        $this->assertFalse(
            app(BusinessMembershipResolver::class)->isActiveMember($this->cashier, $business->id),
        );
    }

    // ---------------------------------------------------------------------
    // Remove + owner guard
    // ---------------------------------------------------------------------

    public function test_platform_admin_can_remove_member_and_detaches_outlets(): void
    {
        $business = $this->provision('Toko Alfa');
        $pusat = $business->outlets()->where('code', 'PUSAT')->firstOrFail();

        $this->addMember($business, [
            'user_id' => $this->cashier->id,
            'role' => BusinessUser::ROLE_STAFF,
            'outlet_ids' => [$pusat->id],
            'default_outlet_id' => $pusat->id,
        ])->assertRedirect();

        $member = $this->member($business, $this->cashier);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->delete(route('businesses.members.destroy', [$business->id, $member->id]))
            ->assertRedirect(route('businesses.show', $business->id));

        $this->assertSame(0, BusinessUser::where('business_id', $business->id)->count());
        $this->assertSame(0, DB::table('user_outlets')->where('user_id', $this->cashier->id)->count());
        $this->assertSame(1, AuditLog::where('event', 'business.member.removed')->count());
    }

    public function test_owner_membership_cannot_be_removed_or_demoted(): void
    {
        $business = $this->provision('Toko Owner', $this->manager->id);
        $owner = $this->member($business, $this->manager);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->delete(route('businesses.members.destroy', [$business->id, $owner->id]))
            ->assertStatus(422);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.members.update', [$business->id, $owner->id]), [
                'role' => BusinessUser::ROLE_STAFF,
                'status' => BusinessUser::STATUS_ACTIVE,
            ])
            ->assertStatus(422);

        $this->assertSame(1, BusinessUser::where('business_id', $business->id)->count());
        $this->assertSame(BusinessUser::ROLE_OWNER, $owner->fresh()->role);
    }

    public function test_member_of_another_business_is_not_touchable(): void
    {
        $business = $this->provision('Toko Alfa');
        $other = $this->provision('Toko Beta', $this->manager->id);
        $foreignMember = $this->member($other, $this->manager);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->delete(route('businesses.members.destroy', [$business->id, $foreignMember->id]))
            ->assertNotFound();
    }

    public function test_member_writes_require_password_confirmation(): void
    {
        $business = $this->provision('Toko Alfa');

        $this->actingAs($this->superAdmin)
            ->post(route('businesses.members.store', $business->id), [
                'user_id' => $this->cashier->id,
                'role' => BusinessUser::ROLE_STAFF,
            ])
            ->assertRedirect(route('password.confirm'));
    }
}
