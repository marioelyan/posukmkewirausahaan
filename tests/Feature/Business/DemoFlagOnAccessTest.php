<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end proof that the demo memberships close the tenancy gap.
 *
 * The demo seeder provisions a business + memberships, so with the flag ON the
 * demo manager (business role `manager`) reaches a manager route, the demo
 * cashier (`staff`) is scoped down, and the super-admin still uses the explicit
 * platform path. Before this slice every non-super demo account had no
 * membership and was rejected outright.
 */
class DemoFlagOnAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->artisan('seed:demo', ['--force' => true])->assertSuccessful();

        config()->set('multi_business.enabled', true);
    }

    public function test_demo_manager_reaches_manager_routes_when_flag_on(): void
    {
        $business = Business::firstOrFail();
        $manager = User::where('email', 'manager@gmail.com')->firstOrFail();

        $this->actingAs($manager)
            ->withSession(['active_business_id' => $business->id])
            ->get('/dashboard/products')
            ->assertOk();
    }

    public function test_demo_cashier_is_scoped_by_staff_role_when_flag_on(): void
    {
        $business = Business::firstOrFail();
        $cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();

        // Dashboard is allowed (staff carries dashboard-access)...
        $this->actingAs($cashier)
            ->withSession(['active_business_id' => $business->id])
            ->get(route('dashboard'))
            ->assertOk();

        // ...but products administration is not part of the staff capability set.
        $this->actingAs($cashier)
            ->withSession(['active_business_id' => $business->id])
            ->get('/dashboard/products')
            ->assertForbidden();
    }

    public function test_demo_super_admin_reaches_dashboard_when_flag_on(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
