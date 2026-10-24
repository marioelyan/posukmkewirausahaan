<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * K1 Konsinyasi: kaitan produk -> supplier (penitip) + penanda konsinyasi.
 *
 * Belum ada hutang otomatis di sini (itu K2): K1 hanya memastikan produk
 * bisa ditandai titipan, dan penanda itu tidak bisa yatim (tanpa penitip).
 */
class ConsignmentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->actingAs($this->admin);

        $this->category = Category::create([
            'name' => 'Kategori Test',
            'image' => 'categories/test.jpg',
            'description' => 'Kategori untuk test',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Ibu Ani',
            'phone' => '081234567890',
        ]);
    }

    private function webPayload(array $overrides = []): array
    {
        return array_merge([
            'image' => UploadedFile::fake()->image('product.png'),
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'category_id' => $this->category->id,
            'buy_price' => 2500,
            'sell_price' => 3000,
            'stock' => 100,
            'tax_rate' => 0,
        ], $overrides);
    }

    private function plainProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'category_id' => $this->category->id,
            'buy_price' => 2500,
            'sell_price' => 3000,
            'stock' => 100,
            'tax_rate' => 0,
        ], $overrides));
    }

    public function test_admin_can_create_consignment_product_with_supplier(): void
    {
        $response = $this->post(route('products.store'), $this->webPayload([
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]));

        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'title' => 'Produk Uji',
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);
    }

    public function test_consignment_product_requires_supplier_on_web(): void
    {
        $response = $this->post(route('products.store'), $this->webPayload([
            'is_consignment' => true,
        ]));

        $response->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_admin_can_update_product_to_consignment(): void
    {
        $product = $this->plainProduct();

        $response = $this->put(route('products.update', $product), $this->webPayload([
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]));

        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);
    }

    public function test_admin_can_unlink_supplier_and_unflag(): void
    {
        $product = $this->plainProduct([
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);

        $this->put(route('products.update', $product), $this->webPayload([
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'supplier_id' => null,
            'is_consignment' => false,
        ]))->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => null,
            'is_consignment' => false,
        ]);
    }

    public function test_deleting_supplier_unflags_its_products(): void
    {
        $product = $this->plainProduct([
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);

        $this->supplier->delete();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => null,
            'is_consignment' => false,
        ]);
    }

    public function test_api_can_create_consignment_product(): void
    {
        Sanctum::actingAs($this->admin, ['products-create']);

        $response = $this->postJson('/api/v1/products', [
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Titipan API',
            'category_id' => $this->category->id,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
            'buy_price' => 2500,
            'sell_price' => 3000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_consignment', true);
        $response->assertJsonPath('data.supplier.name', 'Ibu Ani');

        $this->assertDatabaseHas('products', [
            'title' => 'Produk Titipan API',
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);
    }

    public function test_api_rejects_consignment_without_supplier(): void
    {
        Sanctum::actingAs($this->admin, ['products-create']);

        $response = $this->postJson('/api/v1/products', [
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'title' => 'Produk Yatim',
            'category_id' => $this->category->id,
            'is_consignment' => true,
            'buy_price' => 2500,
            'sell_price' => 3000,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('supplier_id');
    }

    public function test_api_partial_update_can_flag_product_with_existing_supplier(): void
    {
        Sanctum::actingAs($this->admin, ['products-edit']);

        $product = $this->plainProduct(['supplier_id' => $this->supplier->id]);

        // Hanya kirim flag, tanpa mengulang supplier_id: harus lolos karena
        // produk sudah punya supplier tersimpan.
        $response = $this->patchJson('/api/v1/products/'.$product->id, [
            'is_consignment' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);
    }

    public function test_api_partial_update_rejects_flag_without_any_supplier(): void
    {
        Sanctum::actingAs($this->admin, ['products-edit']);

        $product = $this->plainProduct();

        $response = $this->patchJson('/api/v1/products/'.$product->id, [
            'is_consignment' => true,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('supplier_id');
    }
}
