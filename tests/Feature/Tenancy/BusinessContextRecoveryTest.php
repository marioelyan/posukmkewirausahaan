<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression cover for the "logout -> login again -> 403" lockout.
 *
 * Two things used to dead-end a user inside `business.required`:
 *
 *  1. a member with several active memberships had no `active_business_id`
 *     after a fresh login, so nothing resolved and the switcher — which lives
 *     inside that very group — was unreachable; and
 *  2. an `active_business_id` that stopped resolving (membership revoked,
 *     business suspended) was never cleared, so every later request kept 403ing
 *     super-admins as well.
 *
 * Login now writes a verified selection, and a session selection that no longer
 * verifies is dropped and re-resolved. Both must heal without weakening the
 * cross-business boundary: a foreign selection still never grants access, and
 * stateless API headers stay strict.
 */
class BusinessContextRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        config()->set('multi_business.enabled', true);
        config()->set('security.bot_guard.enabled', false);

        Route::middleware(['web', 'business', 'business.required'])
            ->get('/_test/business-required', fn () => response()->json(['ok' => true]));
    }

    private function business(string $name, string $status = Business::STATUS_ACTIVE): Business
    {
        return Business::create([
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
            'status' => $status,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    private function user(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());
        $user->markEmailAsVerified();

        return $user;
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

    private function openShift(User $user, Business $business): CashierShift
    {
        $outlet = Outlet::create([
            'business_id' => $business->id,
            'code' => 'OUT-'.uniqid(),
            'name' => 'Outlet '.$business->name,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::create([
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
            'code' => 'WH-'.uniqid(),
            'name' => 'Gudang '.$business->name,
            'type' => 'branch',
            'is_active' => true,
        ]);

        return CashierShift::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'opened_by' => $user->id,
            'warehouse_id' => $warehouse->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    private function login(string $email): void
    {
        $this->post('/login', ['email' => $email, 'password' => 'password'])->assertRedirect();
    }

    public function test_member_with_several_memberships_can_log_out_and_back_in(): void
    {
        $owner = $this->user('manager', 'owner@example.test');
        $a = $this->business('toko-a');
        $b = $this->business('toko-b');
        $this->member($owner, $a, BusinessUser::ROLE_OWNER);
        $this->member($owner, $b, BusinessUser::ROLE_MANAGER);

        $this->login('owner@example.test');
        $this->get('/dashboard')->assertOk();

        // The owner membership wins over the manager one.
        $this->assertSame($a->id, session('active_business_id'));

        $this->post('/logout')->assertRedirect('/');

        $this->login('owner@example.test');
        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard/transactions')->assertOk();
    }

    public function test_login_prefers_the_business_owning_the_open_shift(): void
    {
        $cashier = $this->user('manager', 'onshift@example.test');
        $preferred = $this->business('toko-preferred');
        $shifted = $this->business('toko-shifted');
        // The role-ranked default (owner > manager) would land on `$preferred`;
        // the open shift in `$shifted` has to win instead. Being placed
        // elsewhere would strand the operator: no shift there, and the business
        // switcher is locked mid-shift.
        $this->member($cashier, $preferred, BusinessUser::ROLE_OWNER);
        $this->member($cashier, $shifted, BusinessUser::ROLE_MANAGER);
        $this->openShift($cashier, $shifted);

        $this->login('onshift@example.test');

        $this->assertSame($shifted->id, session('active_business_id'));
    }

    public function test_stale_selection_heals_to_the_business_owning_the_open_shift(): void
    {
        $cashier = $this->user('manager', 'healshift@example.test');
        $preferred = $this->business('toko-preferred');
        $shifted = $this->business('toko-shifted');
        $suspended = $this->business('toko-suspended', Business::STATUS_SUSPENDED);
        $this->member($cashier, $preferred, BusinessUser::ROLE_OWNER);
        $this->member($cashier, $shifted, BusinessUser::ROLE_MANAGER);
        $this->openShift($cashier, $shifted);

        $this->actingAs($cashier)
            ->withSession(['active_business_id' => $suspended->id])
            ->get('/dashboard')
            ->assertOk();

        $this->assertSame($shifted->id, session('active_business_id'));
    }

    public function test_member_with_one_membership_lands_there_after_login(): void
    {
        $owner = $this->user('manager', 'single@example.test');
        $mine = $this->business('toko-mine');
        $this->member($owner, $mine, BusinessUser::ROLE_MANAGER);

        $this->login('single@example.test');
        $this->get('/dashboard')->assertOk();
        $this->assertSame($mine->id, session('active_business_id'));
    }

    public function test_super_admin_keeps_the_first_active_business_fallback(): void
    {
        $admin = $this->user('super-admin', 'admin@example.test');
        $this->business('toko-active');
        $this->business('toko-suspended', Business::STATUS_SUSPENDED);

        $this->login('admin@example.test');
        $this->get('/dashboard')->assertOk();

        // No selection is pinned for the platform path, and any leftover one
        // would have to be dropped instead of failing closed.
        $this->assertNull(session('active_business_id'));
    }

    public function test_super_admin_lands_on_business_admin_when_no_business_is_active(): void
    {
        $admin = $this->user('super-admin', 'nowhere@example.test');
        $this->business('toko-suspended', Business::STATUS_SUSPENDED);

        $this->post('/login', ['email' => 'nowhere@example.test', 'password' => 'password'])
            ->assertRedirect(route('businesses.index'));

        // They can reach the page that lets them reactivate a business...
        $this->get(route('businesses.index'))->assertOk();

        // ...while enforcement itself still fails closed.
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_member_without_any_membership_lands_on_the_access_page(): void
    {
        $member = $this->user('manager', 'nomembership@example.test');
        $this->business('toko-a');

        $this->post('/login', ['email' => 'nomembership@example.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard.access'));

        $this->get(route('dashboard.access'))->assertOk();
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_stale_session_selection_heals_to_the_first_active_business(): void
    {
        $admin = $this->user('super-admin', 'stale@example.test');
        $this->business('toko-active');
        $suspended = $this->business('toko-suspended', Business::STATUS_SUSPENDED);

        $this->actingAs($admin)
            ->withSession(['active_business_id' => $suspended->id])
            ->get('/dashboard')
            ->assertOk();

        // The unresolvable value is gone, so nothing keeps failing closed.
        $this->assertNull(session('active_business_id'));
        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_stale_session_selection_heals_to_the_users_own_business(): void
    {
        $owner = $this->user('manager', 'heals@example.test');
        $mine = $this->business('toko-mine');
        $foreign = $this->business('toko-foreign');
        $this->member($owner, $mine, BusinessUser::ROLE_OWNER);

        $this->actingAs($owner)
            ->withSession(['active_business_id' => $foreign->id])
            ->get('/dashboard')
            ->assertOk();

        // Never the foreign one: the selection healed to their own business.
        $this->assertSame($mine->id, session('active_business_id'));
    }

    public function test_stale_selection_heals_for_a_member_with_several_businesses(): void
    {
        $manager = $this->user('manager', 'several@example.test');
        $owned = $this->business('toko-owned');
        $also = $this->business('toko-also');
        $suspended = $this->business('toko-suspended', Business::STATUS_SUSPENDED);
        $this->member($manager, $owned, BusinessUser::ROLE_OWNER);
        $this->member($manager, $also, BusinessUser::ROLE_STAFF);

        $this->actingAs($manager)
            ->withSession(['active_business_id' => $suspended->id])
            ->get('/dashboard')
            ->assertOk();

        // Re-placed into a business they are still a member of rather than
        // being dropped into a contextless 403 mid-session.
        $this->assertSame($owned->id, session('active_business_id'));
    }

    public function test_stale_session_selection_still_fails_closed_without_a_membership(): void
    {
        $member = $this->user('manager', 'revoked@example.test');
        $foreign = $this->business('toko-foreign');

        $this->actingAs($member)
            ->withSession(['active_business_id' => $foreign->id])
            ->get('/dashboard')
            ->assertForbidden();

        $this->assertNull(session('active_business_id'));
    }

    public function test_api_header_selection_stays_strict(): void
    {
        $member = $this->user('manager', 'api@example.test');
        $mine = $this->business('toko-mine');
        $foreign = $this->business('toko-foreign');
        $this->member($member, $mine, BusinessUser::ROLE_MANAGER);

        // Entitled business resolves.
        $this->actingAs($member)
            ->withHeader('X-Business-Id', (string) $mine->id)
            ->get('/_test/business-required')
            ->assertOk();

        // A foreign business must never silently fall back to another one.
        $this->actingAs($member)
            ->withHeader('X-Business-Id', (string) $foreign->id)
            ->get('/_test/business-required')
            ->assertForbidden();

        // No header and no session: multi-membership stays fail-closed for API.
        $other = $this->business('toko-c');
        $this->member($member, $other, BusinessUser::ROLE_STAFF);

        $this->actingAs($member)
            ->get('/_test/business-required')
            ->assertForbidden();
    }
}
