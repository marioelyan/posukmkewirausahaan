<?php

namespace Tests\Feature\Dashboard;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/` is the front door of the app: the PWA `start_url`, every logo link and
 * the logout flow (`redirect('/')`) all pass through it.
 *
 * It used to send *every* authenticated visitor to `/dashboard/access`, so a
 * signed-in admin who reopened the app was told "Anda tidak memiliki akses
 * dashboard" — and logging out and back in only hid the symptom, because
 * `AuthenticatedSessionController::store()` lands on `dashboard` and never
 * routes through `/` again.
 */
class AccessLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // The root URL is the landing page only once the install wizard is done;
        // otherwise `/` answers `setup.index` before anything else.
        Setting::set('app_setup_completed', true);

        config()->set('multi_business.enabled', true);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->markEmailAsVerified();
        $admin->assignRole('super-admin');

        return $admin;
    }

    private function memberWith(string $permission, ?Business $business = null): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo($permission);

        if ($business) {
            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => BusinessUser::ROLE_STAFF,
                'status' => BusinessUser::STATUS_ACTIVE,
            ]);
        }

        return $user;
    }

    private function activeBusiness(): Business
    {
        return Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->activeBusiness();

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_super_admin_lands_on_dashboard(): void
    {
        $this->activeBusiness();

        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_member_with_permission_and_context_lands_on_dashboard(): void
    {
        $business = $this->activeBusiness();
        $user = $this->memberWith('dashboard-access', $business);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_super_admin_without_active_business_lands_on_the_access_page(): void
    {
        // No business row at all: `/dashboard` answers 403 through
        // business.required, so the access page stays the honest landing.
        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertRedirect(route('dashboard.access'));
    }

    public function test_member_without_business_context_lands_on_the_access_page(): void
    {
        $this->activeBusiness();

        // `dashboard-access` alone is not enough: without a resolvable context
        // `/dashboard` answers 403 (business.required), so the member is kept on
        // the access page instead of being bounced through an error page.
        $user = $this->memberWith('dashboard-access');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard.access'));
    }

    public function test_member_without_dashboard_permission_lands_on_the_access_page(): void
    {
        $business = $this->activeBusiness();
        $user = $this->memberWith('users-access', $business);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/')
            ->assertRedirect(route('dashboard.access'));
    }
}
