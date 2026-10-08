<?php

namespace Tests\Feature\Members;

use App\Models\Customer;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'customers-access',
            'customers-create',
            'customers-edit',
            'transactions-access',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_member_index_can_filter_by_member_code_and_status(): void
    {
        $user = $this->createUserWithPermissions(['customers-access']);

        $activeMember = Customer::create([
            'name' => 'Member Aktif',
            'no_telp' => '62811001',
            'address' => 'Jl. Aktif',
            'is_loyalty_member' => true,
            'member_code' => 'MEM-1001',
            'loyalty_tier' => LoyaltyService::TIER_GOLD,
            'loyalty_points' => 25,
            'loyalty_total_spent' => 150000,
            'loyalty_transaction_count' => 3,
            'loyalty_member_since' => now()->subMonths(3),
        ]);

        Customer::create([
            'name' => 'Mantan Member',
            'no_telp' => '62811002',
            'address' => 'Jl. Nonaktif',
            'is_loyalty_member' => false,
            'member_code' => 'MEM-1002',
            'loyalty_tier' => LoyaltyService::TIER_SILVER,
            'loyalty_points' => 10,
            'loyalty_member_since' => now()->subMonths(2),
        ]);

        $this->actingAs($user)
            ->get(route('members.index', ['search' => 'MEM-1001', 'status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Members/Index')
                ->where('filters.search', 'MEM-1001')
                ->where('filters.status', 'active')
                ->where('members.data.0.id', $activeMember->id)
                ->count('members.data', 1));
    }

    public function test_member_store_creates_active_member_with_generated_code(): void
    {
        $user = $this->createUserWithPermissions(['customers-create']);
        $this->seedRegionHierarchy();

        $response = $this->actingAs($user)->post(route('members.store'), [
            'name' => 'Member Baru',
            'no_telp' => '62812001',
            'address' => 'Jl. Member Baru',
            'is_loyalty_member' => true,
            'loyalty_tier' => LoyaltyService::TIER_SILVER,
            'province_id' => '11',
            'regency_id' => '11.01',
            'district_id' => '11.01.01',
            'village_id' => '11.01.01.1001',
        ]);

        $customer = Customer::query()->latest('id')->first();

        $response->assertRedirect(route('members.index'));
        $this->assertNotNull($customer);
        $this->assertTrue($customer->is_loyalty_member);
        $this->assertSame(LoyaltyService::TIER_SILVER, $customer->loyalty_tier);
        $this->assertNotNull($customer->member_code);
        $this->assertSame('11', $customer->province_id);
    }

    public function test_pos_ajax_customer_registration_can_create_member(): void
    {
        $user = $this->createUserWithPermissions(['customers-create']);

        $response = $this->actingAs($user)->postJson(route('customers.storeAjax'), [
            'name' => 'POS Member',
            'no_telp' => '62813001',
            'address' => 'Jl. POS Member',
            'is_loyalty_member' => true,
            'loyalty_tier' => LoyaltyService::TIER_GOLD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('customer.is_loyalty_member', true)
            ->assertJsonPath('customer.loyalty_tier', LoyaltyService::TIER_GOLD);

        $this->assertDatabaseHas('customers', [
            'name' => 'POS Member',
            'is_loyalty_member' => true,
            'loyalty_tier' => LoyaltyService::TIER_GOLD,
        ]);
    }

    public function test_customer_can_be_upgraded_and_member_deactivation_preserves_history(): void
    {
        $user = $this->createUserWithPermissions(['customers-edit']);
        $this->seedRegionHierarchy();

        $customer = Customer::create([
            'name' => 'Calon Member',
            'no_telp' => '62814001',
            'address' => 'Jl. Calon',
            'province_id' => '11',
            'province_name' => 'Aceh',
            'regency_id' => '11.01',
            'regency_name' => 'Kabupaten Test',
            'district_id' => '11.01.01',
            'district_name' => 'Kecamatan Test',
            'village_id' => '11.01.01.1001',
            'village_name' => 'Kelurahan Test',
        ]);

        $upgradeResponse = $this->actingAs($user)->postJson(
            route('customers.upgrade-member', $customer),
            ['loyalty_tier' => LoyaltyService::TIER_PLATINUM]
        );

        $customer->refresh();

        $upgradeResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('customer.is_loyalty_member', true);
        $this->assertNotNull($customer->member_code);
        $this->assertSame(LoyaltyService::TIER_PLATINUM, $customer->loyalty_tier);

        $memberCode = $customer->member_code;
        $memberSince = $customer->loyalty_member_since;

        $this->actingAs($user)->put(route('members.update', $customer), [
            'name' => $customer->name,
            'no_telp' => (string) $customer->no_telp,
            'address' => $customer->address,
            'is_loyalty_member' => false,
            'loyalty_tier' => $customer->loyalty_tier,
            'province_id' => $customer->province_id,
            'regency_id' => $customer->regency_id,
            'district_id' => $customer->district_id,
            'village_id' => $customer->village_id,
        ])->assertRedirect(route('members.show', $customer));

        $customer->refresh();

        $this->assertFalse($customer->is_loyalty_member);
        $this->assertSame($memberCode, $customer->member_code);
        $this->assertTrue($customer->loyalty_member_since?->equalTo($memberSince));
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function seedRegionHierarchy(): void
    {
        if (! Schema::hasTable('provinces')) {
            Schema::create('provinces', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
            });
        }

        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('province_code')->nullable();
            });
        }

        if (! Schema::hasTable('districts')) {
            Schema::create('districts', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('city_code')->nullable();
            });
        }

        if (! Schema::hasTable('villages')) {
            Schema::create('villages', function ($table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('district_code')->nullable();
            });
        }

        DB::table('provinces')->insert([
            'code' => '11',
            'name' => 'Aceh',
        ]);

        DB::table('cities')->insert([
            'code' => '11.01',
            'name' => 'Kabupaten Test',
            'province_code' => '11',
        ]);

        DB::table('districts')->insert([
            'code' => '11.01.01',
            'name' => 'Kecamatan Test',
            'city_code' => '11.01',
        ]);

        DB::table('villages')->insert([
            'code' => '11.01.01.1001',
            'name' => 'Kelurahan Test',
            'district_code' => '11.01.01',
        ]);
    }
}
