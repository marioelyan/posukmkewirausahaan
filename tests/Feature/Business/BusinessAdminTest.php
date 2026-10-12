<?php

namespace Tests\Feature\Business;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\BusinessProvisioner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Q6-A — platform-admin business management.
 *
 * Decision A (2026-10-12): only the platform super-admin creates additional
 * businesses, via the dashboard (outside the `business.required` group so it
 * works without an active context). Self-service owner signup stays deferred.
 * Writes are gated by `platform.admin` + `step_up`; there is no destroy —
 * suspend is the lifecycle op, and suspending the last active business is
 * blocked so tenancy can never end up with zero active tenants.
 */
class BusinessAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->superAdmin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->manager = User::where('email', 'manager@gmail.com')->firstOrFail();
    }

    private function createBusinessViaHttp(string $name, ?int $ownerId = null)
    {
        return $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('businesses.store'), [
                'name' => $name,
                'owner_id' => $ownerId,
            ]);
    }

    // ---------------------------------------------------------------------
    // Access boundary (platform admin only)
    // ---------------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('businesses.index'))
            ->assertRedirect(route('login'));
    }

    public function test_non_platform_members_are_rejected(): void
    {
        $this->actingAs($this->manager)
            ->get(route('businesses.index'))
            ->assertForbidden();

        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->actingAs($cashier)
            ->get(route('businesses.index'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_list_businesses(): void
    {
        app(BusinessProvisioner::class)->provision('Toko A');

        $this->actingAs($this->superAdmin)
            ->get(route('businesses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Businesses/Index')
                ->has('businesses', 1)
                ->where('businesses.0.name', 'Toko A')
                ->where('multiBusinessEnabled', false));
    }

    // ---------------------------------------------------------------------
    // Provisioning
    // ---------------------------------------------------------------------

    public function test_store_creates_business_with_scoped_skeleton(): void
    {
        $this->createBusinessViaHttp('Toko Indah Jaya')
            ->assertRedirect(route('businesses.index'));

        $business = Business::where('name', 'Toko Indah Jaya')->firstOrFail();
        $this->assertSame(Business::STATUS_ACTIVE, $business->status);
        $this->assertStringStartsWith('toko-indah-jaya-', $business->slug);
        $this->assertNull($business->owner_user_id);

        $pusat = DB::table('outlets')->where('business_id', $business->id)->first();
        $this->assertNotNull($pusat);
        $this->assertSame('PUSAT', $pusat->code);

        $warehouse = Warehouse::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->first();
        $this->assertNotNull($warehouse);
        $this->assertSame('PUSAT', $warehouse->code);
        $this->assertSame('main', $warehouse->type);
        $this->assertSame($pusat->id, $warehouse->outlet_id);

        $this->assertSame(1, AuditLog::where('event', 'business.created')->count());
    }

    public function test_store_assigns_owner_membership(): void
    {
        $this->createBusinessViaHttp('Toko Owner', $this->manager->id)
            ->assertRedirect(route('businesses.index'));

        $business = Business::where('name', 'Toko Owner')->firstOrFail();
        $this->assertSame($this->manager->id, $business->owner_user_id);

        $membership = BusinessUser::where('business_id', $business->id)
            ->where('user_id', $this->manager->id)
            ->firstOrFail();
        $this->assertSame(BusinessUser::ROLE_OWNER, $membership->role);
        $this->assertSame(BusinessUser::STATUS_ACTIVE, $membership->status);
        $this->assertNotNull($membership->default_outlet_id);
        $this->assertSame(
            $business->outlets()->firstOrFail()->id,
            (int) $membership->default_outlet_id,
        );
    }

    public function test_super_admin_cannot_be_owner(): void
    {
        $this->createBusinessViaHttp('Toko Salah', $this->superAdmin->id)
            ->assertSessionHasErrors('owner_id');

        $this->assertSame(0, Business::where('name', 'Toko Salah')->count());
    }

    public function test_missing_owner_is_rejected(): void
    {
        $this->createBusinessViaHttp('Toko Kosong', 99999)
            ->assertSessionHasErrors('owner_id');

        $this->assertSame(0, Business::where('name', 'Toko Kosong')->count());
    }

    public function test_name_is_required(): void
    {
        $this->createBusinessViaHttp('')
            ->assertSessionHasErrors('name');
    }

    public function test_each_business_owns_its_own_pusat_skeleton(): void
    {
        config()->set('multi_business.enabled', true);

        $provisioner = app(BusinessProvisioner::class);
        $first = $provisioner->provision('Bisnis Pertama');
        $second = $provisioner->provision('Bisnis Kedua');

        $outlets = DB::table('outlets')
            ->where('code', 'PUSAT')
            ->orderBy('business_id')
            ->get();

        $this->assertCount(2, $outlets);
        $this->assertSame([$first->id, $second->id], $outlets->pluck('business_id')->all());

        $this->assertSame(2, Warehouse::withoutGlobalScope('business')
            ->where('code', 'PUSAT')
            ->where('type', 'main')
            ->count());

        $this->assertSame(2, Business::active()->count());
    }

    // ---------------------------------------------------------------------
    // Rename + status lifecycle
    // ---------------------------------------------------------------------

    public function test_platform_admin_can_rename_business(): void
    {
        $business = app(BusinessProvisioner::class)->provision('Nama Lama');

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.update', $business->id), ['name' => 'Nama Baru'])
            ->assertRedirect(route('businesses.index'));

        $this->assertSame('Nama Baru', $business->fresh()->name);
        $this->assertSame(1, AuditLog::where('event', 'business.updated')->count());
    }

    public function test_platform_admin_can_suspend_and_reactivate_business(): void
    {
        config()->set('multi_business.enabled', true);

        $first = app(BusinessProvisioner::class)->provision('Bisnis Aktif');
        $target = app(BusinessProvisioner::class)->provision('Bisnis Target');

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.updateStatus', $target->id), ['status' => 'suspended'])
            ->assertRedirect(route('businesses.index'));

        $this->assertSame(Business::STATUS_SUSPENDED, $target->fresh()->status);

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.updateStatus', $target->id), ['status' => 'active'])
            ->assertRedirect(route('businesses.index'));

        $this->assertSame(Business::STATUS_ACTIVE, $target->fresh()->status);
        $this->assertSame(2, AuditLog::where('event', 'business.status_updated')->count());
    }

    public function test_cannot_suspend_the_last_active_business(): void
    {
        config()->set('multi_business.enabled', true);

        $only = app(BusinessProvisioner::class)->provision('Satu-satunya');

        $this->actingAs($this->superAdmin)
            ->withSession($this->recentlyConfirmedSession())
            ->patch(route('businesses.updateStatus', $only->id), ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertSame(Business::STATUS_ACTIVE, $only->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // step_up
    // ---------------------------------------------------------------------

    public function test_writes_require_password_confirmation(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('businesses.store'), ['name' => 'Tanpa Konfirmasi'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, Business::count());
    }
}
