<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Services\OutletAccessService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C7: the Outlets settings page must expose the active business so the UI can
 * show which tenant owns the listed outlets. The list itself is already scoped
 * to the active business by {@see OutletAccessService}, so a
 * page-level context badge is enough (no per-row business column needed).
 */
class OutletBusinessContextTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        config()->set('multi_business.enabled', true);

        $this->business = Business::create([
            'name' => 'Toko Alfa',
            'slug' => 'toko-alfa',
            'status' => Business::STATUS_ACTIVE,
        ]);

        $this->outlet = Outlet::create([
            'business_id' => $this->business->id,
            'code' => 'OUT-A',
            'name' => 'Outlet Alfa',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'arya@gmail.com')->firstOrFail();
    }

    public function test_outlets_page_exposes_active_business_when_tenancy_enabled(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['active_business_id' => $this->business->id])
            ->get(route('settings.outlets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeBusiness.id', $this->business->id)
                ->where('activeBusiness.name', 'Toko Alfa')
                ->has('outlets', 1)
                ->where('outlets.0.id', $this->outlet->id)
            );
    }

    public function test_outlets_page_has_no_business_context_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $this->actingAs($this->admin())
            ->get(route('settings.outlets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeBusiness', null)
            );
    }
}
