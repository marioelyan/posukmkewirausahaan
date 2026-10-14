<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\IndonesiaRegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravolt\Indonesia\Seeds\ProvincesSeeder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerOptionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['customers-create', 'customers-edit'] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_customers_no_telp_and_address_columns_are_nullable(): void
    {
        $columns = collect(Schema::getColumns('customers'))->keyBy('name');

        $this->assertTrue($columns['no_telp']['nullable'], 'customers.no_telp must accept NULL');
        $this->assertTrue($columns['address']['nullable'], 'customers.address must accept NULL');
    }

    public function test_customer_can_be_created_with_only_a_name(): void
    {
        $user = $this->userWithPermission('customers-create');

        $response = $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Tanpa Data Lain',
        ]);

        $response->assertRedirect(route('customers.index'));

        $customer = Customer::query()->latest('id')->first();

        $this->assertNotNull($customer);
        $this->assertSame('Tanpa Data Lain', $customer->name);
        $this->assertNull($customer->no_telp);
        $this->assertNull($customer->address);
        $this->assertNull($customer->province_id);
        $this->assertNull($customer->regency_id);
        $this->assertNull($customer->district_id);
        $this->assertNull($customer->village_id);
    }

    public function test_pos_ajax_registration_allows_missing_phone_and_address(): void
    {
        $user = $this->userWithPermission('customers-create');

        $response = $this->actingAs($user)->postJson(route('customers.storeAjax'), [
            'name' => 'Pelanggan POS',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('customer.name', 'Pelanggan POS');

        $customer = Customer::query()->latest('id')->first();

        $this->assertNull($customer->no_telp);
        $this->assertNull($customer->address);
    }

    public function test_update_can_clear_optional_fields(): void
    {
        $user = $this->userWithPermission('customers-edit');

        $customer = Customer::create([
            'name' => 'Lengkap',
            'no_telp' => '628123456',
            'address' => 'Jl. Awal',
            'province_id' => '11',
            'regency_id' => '1101',
        ]);

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => 'Hanya Nama',
        ])->assertRedirect(route('customers.index'));

        $customer->refresh();

        $this->assertSame('Hanya Nama', $customer->name);
        $this->assertNull($customer->no_telp);
        $this->assertNull($customer->address);
        $this->assertNull($customer->province_id);
        $this->assertNull($customer->regency_id);
    }

    public function test_existing_customer_phone_and_address_are_preserved(): void
    {
        $customer = Customer::create([
            'name' => 'Tetap Ada',
            'no_telp' => '628999',
            'address' => 'Jl. Tetap',
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Tetap Ada',
            'address' => 'Jl. Tetap',
        ]);

        $this->assertSame('628999', (string) $customer->fresh()->no_telp);
    }

    public function test_create_page_has_empty_province_state_before_seed(): void
    {
        $user = $this->userWithPermission('customers-create');

        $this->actingAs($user)->get(route('customers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Customers/Create')
                ->has('provinces', 0));
    }

    public function test_create_page_lists_provinces_after_region_seed(): void
    {
        $user = $this->userWithPermission('customers-create');
        $this->seed(ProvincesSeeder::class);

        $this->actingAs($user)->get(route('customers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Customers/Create')
                ->has('provinces', 38)
                ->where('provinces.0.code', '11'));
    }

    public function test_region_cascade_endpoints_return_filtered_children(): void
    {
        $user = $this->userWithPermission('customers-create');

        DB::table('indonesia_provinces')->insert([
            'code' => '11',
            'name' => 'Aceh',
        ]);
        DB::table('indonesia_cities')->insert([
            'code' => '1101',
            'province_code' => '11',
            'name' => 'Kabupaten Test',
        ]);
        DB::table('indonesia_districts')->insert([
            'code' => '110101',
            'city_code' => '1101',
            'name' => 'Kecamatan Test',
        ]);
        DB::table('indonesia_villages')->insert([
            'code' => '1101012001',
            'district_code' => '110101',
            'name' => 'Kelurahan Test',
        ]);

        $this->actingAs($user)
            ->getJson(route('regions.regencies', ['province_id' => '11']))
            ->assertOk()
            ->assertJsonFragment(['code' => '1101', 'name' => 'Kabupaten Test']);

        $this->actingAs($user)
            ->getJson(route('regions.districts', ['regency_id' => '1101']))
            ->assertOk()
            ->assertJsonFragment(['code' => '110101', 'name' => 'Kecamatan Test']);

        $this->actingAs($user)
            ->getJson(route('regions.villages', ['district_id' => '110101']))
            ->assertOk()
            ->assertJsonFragment(['code' => '1101012001', 'name' => 'Kelurahan Test']);
    }

    public function test_region_seeder_guard_skips_only_when_all_tables_populated(): void
    {
        $seeder = new IndonesiaRegionSeeder;

        $this->assertFalse($seeder->hasCompleteRegionData(), 'empty database must not be skipped');

        DB::table('indonesia_provinces')->insert(['code' => '11', 'name' => 'Aceh']);
        $this->assertFalse($seeder->hasCompleteRegionData(), 'provinces-only database must not be skipped');

        DB::table('indonesia_cities')->insert(['code' => '1101', 'province_code' => '11', 'name' => 'Kabupaten Test']);
        DB::table('indonesia_districts')->insert(['code' => '110101', 'city_code' => '1101', 'name' => 'Kecamatan Test']);
        DB::table('indonesia_villages')->insert(['code' => '1101012001', 'district_code' => '110101', 'name' => 'Kelurahan Test']);

        $this->assertTrue($seeder->hasCompleteRegionData(), 'fully populated database must be skipped');
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }
}
