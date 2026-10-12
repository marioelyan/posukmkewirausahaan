<?php

namespace Tests\Feature\Security;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPrivilegeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users-access', 'users-create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['super-admin', 'cashier', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function managerActor(): User
    {
        $actor = User::factory()->create();
        $actor->markEmailAsVerified();
        $actor->givePermissionTo('users-create');

        return $actor;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => 'new-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'selectedRoles' => ['cashier'],
        ], $overrides);
    }

    public function test_non_super_admin_cannot_assign_super_admin_role(): void
    {
        $actor = $this->managerActor();

        $this->actingAs($actor)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('users.store'), $this->payload([
                'email' => 'escalate@example.com',
                'selectedRoles' => ['super-admin'],
            ]))
            ->assertSessionHasErrors('selectedRoles');

        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.com']);
        $this->assertSame(0, User::role('super-admin')->count());
    }

    public function test_unknown_role_is_rejected(): void
    {
        $actor = $this->managerActor();

        $this->actingAs($actor)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('users.store'), $this->payload(['selectedRoles' => ['does-not-exist']]))
            ->assertSessionHasErrors('selectedRoles.*');
    }

    public function test_non_super_admin_cannot_assign_outside_outlets(): void
    {
        $allowed = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $blocked = Outlet::create(['code' => 'PUT', 'name' => 'Puter']);

        $actor = $this->managerActor();
        $actor->outlets()->attach($allowed->id, ['is_default' => true]);

        $this->actingAs($actor)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('users.store'), $this->payload([
                'outlet_ids' => [$allowed->id, $blocked->id],
                'default_outlet_id' => $allowed->id,
            ]))
            ->assertSessionHasErrors('outlet_ids');

        $this->assertSame(1, User::count());
    }

    public function test_non_super_admin_can_assign_accessible_outlet(): void
    {
        $allowed = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);

        $actor = $this->managerActor();
        $actor->outlets()->attach($allowed->id, ['is_default' => true]);

        $this->actingAs($actor)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('users.store'), $this->payload([
                'outlet_ids' => [$allowed->id],
                'default_outlet_id' => $allowed->id,
            ]))
            ->assertRedirect(route('users.index'));

        $created = User::where('name', 'New User')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('cashier'));
        $this->assertSame([$allowed->id], $created->outlets->pluck('id')->all());
    }

    public function test_super_admin_can_assign_super_admin_role_and_any_outlet(): void
    {
        $outlet = Outlet::create(['code' => 'PUT', 'name' => 'Puter']);

        $actor = User::factory()->create();
        $actor->markEmailAsVerified();
        $actor->assignRole('super-admin');

        $this->actingAs($actor)
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('users.store'), [
                'name' => 'Second Admin',
                'email' => 'second-admin@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'selectedRoles' => ['super-admin'],
                'outlet_ids' => [$outlet->id],
                'default_outlet_id' => $outlet->id,
            ])
            ->assertRedirect(route('users.index'));

        $created = User::where('email', 'second-admin@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('super-admin'));
    }
}
