<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retur supplier manual (tanpa GR) tidak boleh berakhir 404.
 *
 * Regresi dari laporan produksi 2026-10-28: retur manual tersimpan dengan
 * warehouse_id NULL (form lama tidak punya pilihan gudang), lalu halaman
 * detailnya 404 dan retur itu tidak muncul di daftar.
 */
class SupplierReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $pusat;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->pusat = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // Dua outlet aktif mematikan legacySingleOutletBypass, sehingga
        // retur tanpa gudang benar-benar 404 tanpa perbaikan ini (bukan
        // lolos lewat jalur bypass).
        foreach (['TOKOA', 'TOKOB'] as $code) {
            Outlet::create([
                'code' => $code,
                'name' => 'Outlet '.$code,
                'is_active' => true,
                'is_sales_enabled' => true,
            ]);
        }

        $this->supplier = Supplier::create(['name' => 'Ibu Ani', 'phone' => '081234567890']);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => Category::create([
                'name' => 'Kategori '.Str::upper(Str::random(5)),
                'description' => 'Kategori pengujian',
                'image' => 'category.png',
            ])->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji '.Str::upper(Str::random(4)),
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 2500,
            'sell_price' => 3000,
            'stock' => 100,
            'tax_rate' => 0,
        ], $overrides));
    }

    public function test_manual_return_without_gr_gets_default_warehouse(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(route('supplier-returns.store'), [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $product->id, 'qty_returned' => 5, 'unit_price' => 2500],
            ],
        ]);

        $response->assertRedirect();

        $return = SupplierReturn::firstOrFail();
        $this->assertEquals('draft', $return->status);
        $this->assertEquals($this->pusat->id, $return->warehouse_id);
        $response->assertRedirect(route('supplier-returns.show', $return));
    }

    public function test_legacy_null_warehouse_return_is_listed_and_openable(): void
    {
        $return = SupplierReturn::create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => null,
            'document_number' => 'SR-REG-001',
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('supplier-returns.index'))
            ->assertOk()
            ->assertSee($return->document_number);

        $this->actingAs($this->admin)->get(route('supplier-returns.show', $return))
            ->assertOk();
    }

    public function test_complete_manual_return_decrements_global_and_warehouse_stock(): void
    {
        $product = $this->makeProduct(['stock' => 100]);
        $product->warehouses()->attach($this->pusat->id, ['stock' => 50]);

        $this->actingAs($this->admin)->post(route('supplier-returns.store'), [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $product->id, 'qty_returned' => 5, 'unit_price' => 2500],
            ],
        ]);

        $return = SupplierReturn::firstOrFail();
        $this->assertEquals($this->pusat->id, $return->warehouse_id);

        $this->actingAs($this->admin)->post(route('supplier-returns.complete', $return))
            ->assertRedirect();

        $this->assertEquals('completed', $return->fresh()->status);
        $this->assertEquals(95, $product->fresh()->stock);
        $this->assertEquals(
            45,
            $product->fresh()->warehouses()->where('warehouses.id', $this->pusat->id)->first()->pivot->stock
        );
    }
}
