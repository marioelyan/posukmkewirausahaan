<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'permissions-access',
            'users-access',
            'roles-access',
            'categories-access',
            'products-access',
            'customers-access',
            'transactions-access',
            'receivables-access',
            'payables-access',
            'suppliers-access',
            'reports-access',
            'profits-access',
            'payment-settings-access',
            'stock-opnames-access',
            'stock-mutations-access',
            'sales-returns-access',
            'cashier-shifts-access',
            'audit-logs-access',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_super_admin_can_access_permission_guarded_route_without_direct_permission_assignment(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::create([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]));

        $response = $this->actingAs($user)->get(route('permissions.index'));

        $response->assertOk();
    }

    public function test_non_super_admin_still_gets_403_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('permissions.index'));

        $response->assertForbidden();
    }

    public function test_unauthorized_json_request_returns_json_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('permissions.index'));

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki izin untuk mengakses halaman tersebut.',
            ]);
    }

    public function test_role_seeder_normalizes_legacy_permission_access_role(): void
    {
        $legacyRole = Role::create([
            'name' => 'permission-access',
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $user->assignRole($legacyRole);

        $this->seed(RoleSeeder::class);

        $this->assertDatabaseMissing('roles', [
            'name' => 'permission-access',
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'permissions-access',
        ]);

        $user->refresh();

        $this->assertTrue($user->hasRole('permissions-access'));
        $this->assertFalse($user->hasRole('permission-access'));
    }
}
