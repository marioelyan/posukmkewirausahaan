<?php

namespace Tests\Feature\Setup;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
    }

    public function test_checklist_all_false_on_empty_database(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('setupChecklist.store_profile', false)
            ->where('setupChecklist.category', false)
            ->where('setupChecklist.product', false)
            ->where('setupChecklist.customer', false)
            ->where('setupChecklist.transaction', false)
        );
    }

    public function test_checklist_flags_completed_steps(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();

        Setting::set('app_setup_completed', '1');
        Category::create(['name' => 'Kat', 'image' => '', 'description' => '']);

        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $product = Product::create([
            'image' => '',
            'description' => '',
            'title' => 'Produk Uji',
            'sku' => 'TEST-001',
            'barcode' => '1234567890',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 5,
            'tax_rate' => 0,
            'category_id' => Category::first()->id,
        ]);
        $warehouse->products()->attach($product->id, ['stock' => 5]);

        Customer::create([
            'name' => 'Pelanggan Uji',
            'no_telp' => '081234567890',
            'address' => 'Jl. Uji No. 1',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('setupChecklist.store_profile', true)
            ->where('setupChecklist.category', true)
            ->where('setupChecklist.product', true)
            ->where('setupChecklist.customer', true)
            ->where('setupChecklist.transaction', false)
        );
    }
}
