<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\StockMutationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductWarehouseAssociationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outletPusat;

    private Outlet $outletMal;

    private Warehouse $pusatWarehouse;

    private Warehouse $malWarehouse;

    private User $manager;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->outletPusat = Outlet::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'is_active' => true,
            'is_sales_enabled' => false,
        ]);
        $this->outletMal = Outlet::create([
            'code' => 'MAL',
            'name' => 'Malabar',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->pusatWarehouse = Warehouse::create([
            'outlet_id' => $this->outletPusat->id,
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->malWarehouse = Warehouse::create([
            'outlet_id' => $this->outletMal->id,
            'code' => 'WH-MAL',
            'name' => 'Gudang Malabar',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->manager = User::factory()->create();
        $this->manager->markEmailAsVerified();
        $this->manager->outlets()->attach($this->outletMal->id, ['is_default' => true]);

        foreach ([
            'products-access',
            'products-create',
            'products-import',
            'transactions-access',
        ] as $permission) {
            $this->manager->givePermissionTo(Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]));
        }

        $this->category = Category::create([
            'name' => 'Kategori Asosiasi',
            'image' => 'categories/test.jpg',
            'description' => 'Kategori untuk test asosiasi gudang',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Asosiasi Gudang',
            'description' => 'Uji asosiasi product warehouse.',
            'category_id' => $this->category->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 40,
            'tax_rate' => 0,
        ], $overrides);
    }

    public function test_store_without_warehouse_id_attaches_to_users_sales_warehouse_and_appears_on_pos(): void
    {
        $this->actingAs($this->manager)
            ->post(route('products.store'), $this->validPayload())
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();
        $this->assertNotNull($product);

        // pivot attached to the user's sales warehouse, NOT to PUSAT/main
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $this->malWarehouse->id,
            'stock' => 40,
        ]);
        $this->assertDatabaseMissing('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $this->pusatWarehouse->id,
        ]);
        $this->assertSame(40, (int) $product->fresh()->stock);

        // initial stock mutation recorded against the sales warehouse
        $mutation = StockMutation::where('reference_type', 'product_create')
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($mutation);
        $this->assertSame($this->malWarehouse->id, $mutation->warehouse_id);

        // visible in product management
        $this->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Products/Index')
                ->where('products.data.0.id', $product->id));

        // open a shift on the sales warehouse → product visible on POS
        app(CashierShiftService::class)->openShift(
            $this->manager,
            $this->manager,
            100000,
            null,
            $this->malWarehouse->id,
        );

        $this->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Transactions/Index')
                ->where('products', fn ($products) => collect($products)->pluck('id')->contains($product->id)));

        // barcode search resolves it with sales-warehouse stock
        $this->post(route('transactions.searchProduct'), ['barcode' => $product->barcode])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.stock', 40);

        // add to cart lands on the sales warehouse
        $this->post(route('transactions.addToCart'), [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertRedirect(route('transactions.index'));

        $this->assertDatabaseHas('carts', [
            'product_id' => $product->id,
            'warehouse_id' => $this->malWarehouse->id,
        ]);

        // product stocked only outside the shift warehouse stays invisible on POS
        $outside = Product::create([
            'barcode' => 'BRCD-OUTSIDE-01',
            'sku' => 'SKU-OUTSIDE-01',
            'title' => 'Produk Luar Gudang',
            'description' => 'Stok hanya di PUSAT.',
            'image' => 'products/default.png',
            'category_id' => $this->category->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 9,
            'tax_rate' => 0,
        ]);
        $this->pusatWarehouse->products()->attach($outside->id, ['stock' => 9]);

        $this->post(route('transactions.searchProduct'), ['barcode' => $outside->barcode])
            ->assertOk()
            ->assertJsonPath('success', false);
    }

    public function test_store_with_authorized_requested_warehouse_succeeds(): void
    {
        $this->actingAs($this->manager)
            ->post(route('products.store'), $this->validPayload([
                'warehouse_id' => $this->malWarehouse->id,
            ]))
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();
        $this->assertNotNull($product);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $this->malWarehouse->id,
            'stock' => 40,
        ]);
        $this->assertNotNull(
            StockMutation::where('reference_type', 'product_create')
                ->where('product_id', $product->id)
                ->first()
        );
    }

    public function test_store_with_unauthorized_warehouse_forbidden_without_side_effects(): void
    {
        $outletB = Outlet::create([
            'code' => 'PUT',
            'name' => 'Puter',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
        $warehouseB = Warehouse::create([
            'outlet_id' => $outletB->id,
            'code' => 'WH-PUT',
            'name' => 'Gudang Puter',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($this->manager)
            ->post(route('products.store'), $this->validPayload([
                'warehouse_id' => $warehouseB->id,
            ]))
            ->assertForbidden();

        // no orphan product, no pivot, no stock, no mutation
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_warehouse', 0);
        $this->assertDatabaseCount('stock_mutations', 0);
    }

    public function test_store_failure_after_product_creation_rolls_back_everything(): void
    {
        $this->mock(StockMutationService::class, function ($mock): void {
            $mock->shouldReceive('recordInitialStock')
                ->andThrow(new \RuntimeException('forced failure after product creation'));
        });

        $response = $this->actingAs($this->manager)
            ->post(route('products.store'), $this->validPayload());
        $response->assertStatus(500);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_warehouse', 0);
        $this->assertDatabaseCount('stock_mutations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_import_attaches_products_to_users_sales_warehouse(): void
    {
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-001,SKU-IMP-001,Produk Import Asosiasi,Umum,1000,2000,15\n";

        $this->actingAs($this->manager)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $product = Product::where('barcode', 'IMP-001')->first();
        $this->assertNotNull($product);

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $this->malWarehouse->id,
            'stock' => 15,
        ]);
        $this->assertDatabaseMissing('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $this->pusatWarehouse->id,
        ]);
        $this->assertNotNull(
            StockMutation::where('reference_type', 'product_create')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $this->malWarehouse->id)
                ->first()
        );
    }

    public function test_import_with_unauthorized_warehouse_forbidden_without_side_effects(): void
    {
        $outletB = Outlet::create([
            'code' => 'PUT',
            'name' => 'Puter',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
        $warehouseB = Warehouse::create([
            'outlet_id' => $outletB->id,
            'code' => 'WH-PUT',
            'name' => 'Gudang Puter',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-403,SKU-IMP-403,Produk Ditolak,Umum,1000,2000,15\n";

        $this->actingAs($this->manager)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
                'warehouse_id' => $warehouseB->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_warehouse', 0);
    }
}
