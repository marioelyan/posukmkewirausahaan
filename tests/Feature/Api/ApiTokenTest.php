<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products-access', 'products-create', 'pos-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->givePermissionTo(['products-access', 'pos-access']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->assignRole('manager');

        return $user;
    }

    public function test_index_lists_only_own_tokens(): void
    {
        $user = $this->manager();
        $other = $this->manager();

        $user->createToken('milikku', ['products-access']);
        $other->createToken('milik-orang', ['products-access']);

        $this->actingAs($user)
            ->get(route('settings.api-tokens.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings/ApiTokens')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'milikku')
            );
    }

    public function test_store_creates_token_with_abilities_capped_to_own_permissions(): void
    {
        $user = $this->manager();

        $this->withSession($this->recentlyConfirmedSession())
            ->actingAs($user)
            ->post(route('settings.api-tokens.store'), [
                'name' => 'Juleha',
                // products-create bukan milik manager → harus terpotong.
                'abilities' => ['products-access', 'pos-access', 'products-create'],
            ])
            ->assertRedirect(route('settings.api-tokens.index'));

        $token = $user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('Juleha', $token->name);
        $this->assertEqualsCanonicalizing(
            ['products-access', 'pos-access', 'user:read'],
            $token->abilities
        );

        // Token tampil sekali di halaman berikutnya (flash Inertia hidup di
        // level page, bukan di props — jadi pakai hasFlash, bukan has('flash.*')).
        $this->actingAs($user)
            ->get(route('settings.api-tokens.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->hasFlash('api_token'));
    }

    public function test_store_requires_name(): void
    {
        $this->withSession($this->recentlyConfirmedSession())
            ->actingAs($this->manager())
            ->post(route('settings.api-tokens.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_destroy_deletes_own_token_but_not_others(): void
    {
        $user = $this->manager();
        $other = $this->manager();

        $own = $user->createToken('milikku', ['products-access']);
        $foreign = $other->createToken('milik-orang', ['products-access']);

        $this->withSession($this->recentlyConfirmedSession())
            ->actingAs($user)
            ->delete(route('settings.api-tokens.destroy', $foreign->accessToken->id))
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);

        $this->withSession($this->recentlyConfirmedSession())
            ->actingAs($user)
            ->delete(route('settings.api-tokens.destroy', $own->accessToken->id))
            ->assertRedirect(route('settings.api-tokens.index'));

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $own->accessToken->id]);
    }

    public function test_ui_created_token_authenticates_api_with_its_abilities(): void
    {
        $user = $this->manager();

        $this->withSession($this->recentlyConfirmedSession())
            ->actingAs($user)
            ->post(route('settings.api-tokens.store'), [
                'name' => 'Juleha',
                'abilities' => ['products-access'],
            ])
            ->assertRedirect(route('settings.api-tokens.index'));

        // Ambil token mentah dari flash Inertia (hanya tampil sekali).
        $plainToken = session('inertia.flash_data.api_token');
        $this->assertNotEmpty($plainToken, 'Token mentah harus tampil sekali setelah dibuat.');

        // Keluar dari sesi web dulu: guard Sanctum mendahulukan sesi web
        // (TransientToken yang lolos semua ability) di atas Bearer token,
        // jadi tanpa logout, panggilan API di bawah tidak menguji token sungguhan.
        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        // Token hasil UI bisa dipakai ke API sesuai abilities-nya.
        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->getJson('/api/v1/products')
            ->assertOk();

        // Tanpa ability products-create, aksi tulis ditolak.
        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/v1/products', ['title' => 'X'])
            ->assertForbidden();
    }

    public function test_sanctum_acting_as_helper_still_works(): void
    {
        Sanctum::actingAs($this->manager(), ['products-access']);

        $this->getJson('/api/v1/products')->assertOk();
    }
}
