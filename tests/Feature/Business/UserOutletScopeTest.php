<?php

namespace Tests\Feature\Business;

use App\Http\Requests\UserRequest;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C2: the Users create/edit form must offer exactly the outlets the actor is
 * allowed to assign, matching {@see UserRequest}. Under
 * tenancy that also means outlets from other businesses never leak into the
 * form (which would otherwise be rejected on submit).
 */
class UserOutletScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Outlet $outletA;

    private Outlet $outletB;

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

        $this->outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OUT-A',
            'name' => 'Outlet Alfa',
            'is_active' => true,
        ]);

        $this->outletB = Outlet::create([
            'business_id' => $this->businessB->id,
            'code' => 'OUT-B',
            'name' => 'Outlet Beta',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'arya@gmail.com')->firstOrFail();
    }

    public function test_create_form_only_lists_outlets_of_the_active_business(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('outlets', 1)
                ->where('outlets.0.id', $this->outletA->id)
                ->where('outlets.0.code', 'OUT-A')
            );
    }

    public function test_edit_form_only_lists_outlets_of_the_active_business(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('users.edit', $target->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('outlets', 1)
                ->where('outlets.0.id', $this->outletA->id)
            );
    }

    public function test_form_lists_all_active_outlets_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $this->actingAs($this->admin())
            ->get(route('users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('outlets', 2));
    }
}
