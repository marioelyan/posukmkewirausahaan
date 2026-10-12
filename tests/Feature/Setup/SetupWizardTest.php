<?php

namespace Tests\Feature\Setup;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function validPayload(array $branchOverrides = []): array
    {
        $branch = ['outlet_code' => 'MAL', 'outlet_name' => 'Cabang Malabar', 'warehouse_code' => 'WH-MAL', 'warehouse_name' => 'Gudang Malabar'];
        $branch = array_merge($branch, $branchOverrides);

        return [
            'store_name' => 'Toko Berkah',
            'business_type' => 'food',
            'categories' => ['Makanan', 'Minuman'],
            'branches' => [$branch],
            'user_name' => 'Owner',
            'user_email' => 'owner@example.com',
            'password' => 'password123',
            'warehouse_code' => 'PUSAT',
            'warehouse_name' => 'Gudang Utama',
        ];
    }

    public function test_root_redirects_to_setup_before_install(): void
    {
        $this->get('/')->assertRedirect(route('setup.index'));
    }

    public function test_root_redirects_guest_to_login_after_install(): void
    {
        Setting::set('app_setup_completed', true);

        // After install, / is a landing redirect: guest -> login.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_setup_page_is_accessible_before_install(): void
    {
        $this->get(route('setup.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Setup/Wizard')
                ->where('businessTypes.0', [
                    'key' => 'food',
                    'categories' => ['food', 'beverages', 'snacks', 'coffeeTea'],
                ])
                ->where('businessTypes.5', [
                    'key' => 'services',
                    'categories' => ['services', 'products', 'packages'],
                ]));
    }

    public function test_setup_page_redirects_after_install(): void
    {
        Setting::set('app_setup_completed', true);

        $this->get(route('setup.index'))->assertRedirect(route('login'));
    }

    public function test_setup_creates_admin_warehouse_and_categories(): void
    {
        $response = $this->post(route('setup.store'), $this->validPayload());

        $response->assertRedirect(route('login'));

        $user = User::where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('super-admin'));
        $warehouse = Warehouse::where('code', 'PUSAT')->where('type', 'main')->firstOrFail();
        $outlet = Outlet::where('code', 'PUSAT')->firstOrFail();
        $this->assertSame($outlet->id, $warehouse->outlet_id);
        $this->assertFalse($outlet->is_sales_enabled);
        $this->assertTrue($user->outlets()->whereKey($outlet->id)->wherePivot('is_default', true)->exists());
        $this->assertSame(
            ['Makanan', 'Minuman'],
            Category::orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame('Toko Berkah', Setting::get('store_name'));
        $this->assertSame('food', Setting::get('store_business_type'));
        $this->assertTrue(Setting::getBool('app_setup_completed'));
    }

    public function test_setup_creates_branch_outlet_and_warehouse(): void
    {
        $this->post(route('setup.store'), $this->validPayload())->assertRedirect(route('login'));

        $branchOutlet = Outlet::where('code', 'MAL')->firstOrFail();
        $this->assertTrue($branchOutlet->is_sales_enabled);
        $this->assertTrue($branchOutlet->is_active);

        $branchWarehouse = Warehouse::where('code', 'WH-MAL')->firstOrFail();
        $this->assertSame('branch', $branchWarehouse->type);
        $this->assertSame($branchOutlet->id, $branchWarehouse->outlet_id);
        $this->assertTrue($branchWarehouse->is_active);

        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(
            $admin->outlets()->whereKey($branchOutlet->id)->exists(),
            'admin should be assigned to the new branch outlet',
        );
    }

    public function test_setup_creates_multiple_branches(): void
    {
        $payload = $this->validPayload();
        $payload['branches'] = [
            ['outlet_code' => 'MAL', 'outlet_name' => 'Malabar', 'warehouse_code' => 'WH-MAL', 'warehouse_name' => 'Gudang Malabar'],
            ['outlet_code' => 'TKB', 'outlet_name' => 'Taman Kencana', 'warehouse_code' => 'WH-TKB', 'warehouse_name' => 'Gudang TKB'],
            ['outlet_code' => 'PUT', 'outlet_name' => 'Puter', 'warehouse_code' => 'WH-PUT', 'warehouse_name' => 'Gudang Puter'],
        ];

        $this->post(route('setup.store'), $payload)->assertRedirect(route('login'));

        $this->assertSame(4, Outlet::count());
        $this->assertSame(4, Warehouse::count());
        $this->assertSame(
            [1, 2, 3],
            Warehouse::whereIn('code', ['WH-MAL', 'WH-TKB', 'WH-PUT'])
                ->orderBy('sort_order')
                ->pluck('sort_order')
                ->all(),
        );
    }

    public function test_setup_requires_at_least_one_branch(): void
    {
        $payload = $this->validPayload();
        $payload['branches'] = [];

        $this->post(route('setup.store'), $payload)
            ->assertSessionHasErrors(['branches']);
    }

    public function test_setup_rejects_duplicate_branch_outlet_code(): void
    {
        $payload = $this->validPayload();
        $payload['branches'] = [
            ['outlet_code' => 'MAL', 'outlet_name' => 'Malabar', 'warehouse_code' => 'WH-MAL', 'warehouse_name' => 'Gudang Malabar'],
            ['outlet_code' => 'MAL', 'outlet_name' => 'Malabar 2', 'warehouse_code' => 'WH-MAL2', 'warehouse_name' => 'Gudang Malabar 2'],
        ];

        $this->post(route('setup.store'), $payload)
            ->assertSessionHasErrors(['branches.1.outlet_code']);
    }

    public function test_setup_requires_unique_email_and_warehouse_code(): void
    {
        User::create([
            'name' => 'Taken',
            'email' => 'owner@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->post(route('setup.store'), $this->validPayload())
            ->assertSessionHasErrors(['user_email']);
    }

    public function test_setup_rejects_unknown_business_type(): void
    {
        $payload = $this->validPayload();
        $payload['business_type'] = 'crypto';

        $this->post(route('setup.store'), $payload)
            ->assertSessionHasErrors(['business_type']);
    }

    public function test_index_returns_primary_warehouse_when_seeded(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Setting::set('setup_warehouse_id', $warehouse->id);

        $this->get(route('setup.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('primaryWarehouse.id', $warehouse->id)
                ->where('primaryWarehouse.code', 'PUSAT')
                ->where('primaryWarehouse.name', 'Gudang Pusat'));
    }

    public function test_index_returns_null_primary_warehouse_when_not_seeded(): void
    {
        $this->get(route('setup.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('primaryWarehouse', null));
    }

    public function test_store_updates_existing_warehouse_when_warehouse_id_provided(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $payload = $this->validPayload();
        $payload['warehouse_id'] = $warehouse->id;
        $payload['warehouse_code'] = 'PUSAT';
        $payload['warehouse_name'] = 'Gudang Utama';

        $response = $this->post(route('setup.store'), $payload);
        $response->assertRedirect(route('login'));

        $warehouse->refresh();
        $this->assertSame('Gudang Utama', $warehouse->name);
        $this->assertSame('PUSAT', $warehouse->code);
        $this->assertSame($warehouse->id, (int) Setting::get('setup_warehouse_id'));
    }

    public function test_store_rejects_non_pusat_warehouse_when_updating(): void
    {
        $warehouse1 = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Satu',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $warehouse2 = Warehouse::create([
            'code' => 'CABANG',
            'name' => 'Cabang',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $payload = $this->validPayload();
        $payload['warehouse_id'] = $warehouse2->id;
        $payload['warehouse_code'] = 'PUSAT';

        $this->post(route('setup.store'), $payload)
            ->assertSessionHasErrors(['warehouse_id']);
    }

    public function test_store_allows_renaming_same_warehouse_code(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Lama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $payload = $this->validPayload();
        $payload['warehouse_id'] = $warehouse->id;
        $payload['warehouse_code'] = 'PUSAT';
        $payload['warehouse_name'] = 'Gudang Baru';

        $this->post(route('setup.store'), $payload)->assertRedirect(route('login'));

        $warehouse->refresh();
        $this->assertSame('Gudang Baru', $warehouse->name);
    }

    public function test_setup_warehouse_id_is_set_after_successful_setup(): void
    {
        $response = $this->post(route('setup.store'), $this->validPayload());
        $response->assertRedirect(route('login'));

        $warehouse = Warehouse::where('code', 'PUSAT')->first();
        $this->assertSame($warehouse->id, (int) Setting::get('setup_warehouse_id'));
    }

    public function test_setup_bootstraps_default_business_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $this->post(route('setup.store'), $this->validPayload())->assertRedirect(route('login'));

        // A fresh install with the flag on must end up with a business; without
        // one, the fail-closed context resolver rejects even the super-admin.
        $business = Business::firstOrFail();
        $this->assertSame('Toko Berkah', $business->name);
        $this->assertSame(Business::STATUS_ACTIVE, $business->status);

        // The install's outlets, warehouses and master data belong to it.
        $this->assertSame($business->id, (int) Outlet::where('code', 'PUSAT')->value('business_id'));
        $this->assertSame($business->id, (int) Outlet::where('code', 'MAL')->value('business_id'));
        $this->assertSame($business->id, (int) Warehouse::withoutGlobalScope('business')->where('code', 'PUSAT')->value('business_id'));
        $this->assertSame($business->id, (int) Warehouse::withoutGlobalScope('business')->where('code', 'WH-MAL')->value('business_id'));
        $this->assertSame($business->id, (int) Category::withoutGlobalScope('business')->where('name', 'Makanan')->value('business_id'));

        // Store settings are written to the business row (SETTINGS-07) and the
        // layered resolution (`outlet -> business -> global`) prefers it.
        $businessRow = Setting::where('key', 'store_name')->where('business_id', $business->id)->first();
        $this->assertNotNull($businessRow);
        $this->assertSame('Toko Berkah', $businessRow->value);

        app(BusinessContext::class)->setActive($business);
        $this->assertSame('Toko Berkah', Setting::get('store_name'));
        app(BusinessContext::class)->clear();
        // ...while platform keys stay on the single global row.
        $this->assertNull(Setting::where('key', 'app_setup_completed')->value('business_id'));
        $this->assertTrue(Setting::getBool('app_setup_completed'));

        // Super-admin keeps the explicit platform path (AUTH-01): no membership.
        $adminId = User::where('email', 'owner@example.com')->value('id');
        $this->assertSame(0, BusinessUser::where('user_id', $adminId)->count());
    }

    public function test_setup_creates_no_business_when_tenancy_disabled(): void
    {
        $this->post(route('setup.store'), $this->validPayload())->assertRedirect(route('login'));

        $this->assertSame(0, Business::count());
        $this->assertNull(Setting::where('key', 'store_name')->value('business_id'));
    }

    public function test_index_returns_primary_warehouse_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Setting::set('setup_warehouse_id', $warehouse->id);

        $this->get(route('setup.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('primaryWarehouse.id', $warehouse->id));
    }
}
