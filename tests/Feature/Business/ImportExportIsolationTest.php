<?php

namespace Tests\Feature\Business;

use App\Imports\CustomersImport;
use App\Imports\ProductsImport;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Build 4B — Import / Export data isolation.
 *
 * With tenancy enabled a request operating under Business A must not be able
 * to export Business B data (through the export query or an ID filter) or to
 * create/update Business B records through an uploaded CSV. Ownership is always
 * taken from the verified server-side business context, never from the file.
 */
class ImportExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Outlet $outletA;

    private Outlet $outletB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('multi_business.enabled', true);
        app(BusinessContext::class)->clear();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->businessA = Business::factory()->create(['name' => 'Business A']);
        $this->businessB = Business::factory()->create(['name' => 'Business B']);

        $this->outletA = $this->makeOutlet($this->businessA, 'OA', 'Outlet A');
        $this->outletB = $this->makeOutlet($this->businessB, 'OB', 'Outlet B');

        $this->warehouseA = $this->makeWarehouse($this->businessA, $this->outletA, 'WA');
        $this->warehouseB = $this->makeWarehouse($this->businessB, $this->outletB, 'WB');

        $this->userA = $this->makeUser($this->businessA, $this->outletA, 'User A');
        $this->userB = $this->makeUser($this->businessB, $this->outletB, 'User B');

        app(BusinessContext::class)->clear();
    }

    private function makeOutlet(Business $business, string $code, string $name): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
    }

    private function makeWarehouse(Business $business, Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => 'Warehouse '.$code,
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeUser(Business $business, Outlet $outlet, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo([
            'products-export',
            'customers-export',
            'transactions-access',
            'products-import',
            'customers-import',
        ]);
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessUser::ROLE_MANAGER,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function seedProduct(Business $business, Warehouse $warehouse, string $barcode, string $title, int $sellPrice = 2000): Product
    {
        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Category '.$title,
            'description' => '-',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'image' => 'x.png',
            'barcode' => $barcode,
            'sku' => 'SKU-'.$barcode,
            'title' => $title,
            'description' => '-',
            'category_id' => $category->id,
            'buy_price' => 1000,
            'sell_price' => $sellPrice,
            'stock' => 10,
            'tax_rate' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 10]);

        return $product;
    }

    private function seedCustomer(Business $business, string $name): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'name' => $name,
            'no_telp' => '6280'.random_int(1000000, 9999999),
            'address' => '-',
        ]);
    }

    private function seedTransaction(Warehouse $warehouse, string $invoice, int $grandTotal): Transaction
    {
        return Transaction::create([
            'warehouse_id' => $warehouse->id,
            'cashier_id' => User::factory()->create()->id,
            'invoice' => $invoice,
            'cash' => $grandTotal,
            'change' => 0,
            'discount' => 0,
            'grand_total' => $grandTotal,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
    }

    private function as(User $user, Business $business)
    {
        return $this->actingAs($user)->withSession(['active_business_id' => $business->id]);
    }

    /**
     * Read a real (non-faked) `Excel::download` response into a 2D array.
     */
    private function spreadsheetRows($response): array
    {
        $path = $response->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);

        return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
    }

    // 21.1 ------------------------------------------------------- Export reads

    public function test_export_products_only_contains_active_business_data(): void
    {
        $this->seedProduct($this->businessA, $this->warehouseA, 'BC-A', 'Product A');
        $this->seedProduct($this->businessB, $this->warehouseB, 'BC-B', 'Product B');

        $response = $this->as($this->userA, $this->businessA)
            ->get(route('export.products'))
            ->assertOk();

        $content = collect($this->spreadsheetRows($response))->flatten()->implode('|');

        $this->assertStringContainsString('Product A', $content);
        $this->assertStringNotContainsString('Product B', $content);
    }

    public function test_export_customers_only_contains_active_business_data(): void
    {
        $this->seedCustomer($this->businessA, 'Customer A');
        $this->seedCustomer($this->businessB, 'Customer B');

        $response = $this->as($this->userA, $this->businessA)
            ->get(route('export.customers'))
            ->assertOk();

        $content = collect($this->spreadsheetRows($response))->flatten()->implode('|');

        $this->assertStringContainsString('Customer A', $content);
        $this->assertStringNotContainsString('Customer B', $content);
    }

    public function test_transaction_export_only_contains_active_business_data(): void
    {
        $this->seedTransaction($this->warehouseA, 'INV-A', 20_000);
        $this->seedTransaction($this->warehouseB, 'INV-B', 75_000);

        $response = $this->as($this->userA, $this->businessA)
            ->get(route('export.transactions'))
            ->assertOk();

        $content = collect($this->spreadsheetRows($response))->flatten()->implode('|');

        $this->assertStringContainsString('INV-A', $content);
        $this->assertStringNotContainsString('INV-B', $content);
    }

    // 21.2 ------------------------------------------- Cross-business filters

    public function test_transaction_export_rejects_foreign_warehouse_filter(): void
    {
        $this->seedTransaction($this->warehouseA, 'INV-A', 20_000);
        $this->seedTransaction($this->warehouseB, 'INV-B', 75_000);

        $this->as($this->userA, $this->businessA)
            ->get(route('export.transactions', ['warehouse_id' => $this->warehouseB->id]))
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_transaction_export_accepts_own_warehouse_filter(): void
    {
        $this->seedTransaction($this->warehouseA, 'INV-A', 20_000);
        $this->seedTransaction($this->warehouseA, 'INV-A2', 5_000);
        $this->seedTransaction($this->warehouseB, 'INV-B', 75_000);

        $response = $this->as($this->userA, $this->businessA)
            ->get(route('export.transactions', ['warehouse_id' => $this->warehouseA->id]))
            ->assertOk();

        $content = collect($this->spreadsheetRows($response))->flatten()->implode('|');

        $this->assertStringContainsString('INV-A', $content);
        $this->assertStringContainsString('INV-A2', $content);
        $this->assertStringNotContainsString('INV-B', $content);
    }

    // 21.3 ---------------------------------------------------- Export totals

    public function test_transaction_export_totals_exclude_other_business(): void
    {
        $this->seedTransaction($this->warehouseA, 'INV-A', 12_345);
        $this->seedTransaction($this->warehouseB, 'INV-B', 98_765);

        $response = $this->as($this->userA, $this->businessA)
            ->get(route('export.transactions'))
            ->assertOk();

        $rows = $this->spreadsheetRows($response);
        $header = array_shift($rows);
        $grandTotalIndex = array_search('Grand Total', $header, true);

        $this->assertNotFalse($grandTotalIndex, 'Grand Total column missing from export.');

        $total = (int) collect($rows)->sum(fn (array $row) => (int) ($row[$grandTotalIndex] ?? 0));

        $this->assertSame(12_345, $total);
        $this->assertNotSame(111_110, $total);
    }

    // 21.4 / 21.7 --------------------------------------------- Import writes

    public function test_import_products_creates_record_owned_by_active_business(): void
    {
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-A,SKU-IMP-A,Produk Import A,Umum,1000,2000,5\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'barcode' => 'IMP-A',
            'business_id' => $this->businessA->id,
        ]);
        $this->assertDatabaseMissing('products', ['business_id' => $this->businessB->id]);

        $category = Category::withoutGlobalScopes()->where('name', 'Umum')->first();
        $this->assertNotNull($category);
        $this->assertSame($this->businessA->id, $category->business_id);
    }

    public function test_import_products_rejects_foreign_warehouse(): void
    {
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-FKW,SKU-FKW,Produk Gudang Asing,Umum,1000,2000,5\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
                'warehouse_id' => $this->warehouseB->id,
            ])
            ->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseMissing('products', ['barcode' => 'IMP-FKW']);
    }

    public function test_import_products_ignores_uploaded_business_id(): void
    {
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok,business_id\n"
            ."IMP-OVERRIDE,SKU-OVERRIDE,Produk Override,Umum,1000,2000,5,{$this->businessB->id}\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'barcode' => 'IMP-OVERRIDE',
            'business_id' => $this->businessA->id,
        ]);
        $this->assertDatabaseMissing('products', [
            'barcode' => 'IMP-OVERRIDE',
            'business_id' => $this->businessB->id,
        ]);
    }

    public function test_import_products_resolves_category_within_active_business(): void
    {
        // Business B owns a category named "Umum". Business A's import must not
        // attach its product to B's category even though the names match.
        $categoryB = Category::create([
            'business_id' => $this->businessB->id,
            'name' => 'Umum',
            'description' => '-',
        ]);

        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-CAT,SKU-IMP-CAT,Produk Kategori,Umum,1000,2000,5\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $product = Product::withoutGlobalScopes()->where('barcode', 'IMP-CAT')->firstOrFail();
        $category = Category::withoutGlobalScopes()->find($product->category_id);

        $this->assertNotSame($categoryB->id, $product->category_id);
        $this->assertNotNull($category);
        $this->assertSame($this->businessA->id, $category->business_id);
        $this->assertDatabaseHas('categories', ['id' => $categoryB->id, 'business_id' => $this->businessB->id]);
    }

    public function test_import_products_upsert_updates_only_active_business_record(): void
    {
        $productA = $this->seedProduct($this->businessA, $this->warehouseA, 'BC-A', 'Product A', 2000);
        $productB = $this->seedProduct($this->businessB, $this->warehouseB, 'BC-B', 'Product B', 9000);

        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."BC-A,SKU-BC-A,Product A Updated,Umum,1000,3000,5\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertSame(3000, (int) $productA->fresh()->sell_price);
        $this->assertSame(9000, (int) $productB->fresh()->sell_price);
        $this->assertSame($this->businessB->id, $productB->fresh()->business_id);
    }

    public function test_import_products_cannot_hijack_foreign_barcode(): void
    {
        $productB = $this->seedProduct($this->businessB, $this->warehouseB, 'BC-B', 'Product B', 9000);

        // Business A uploads Business B's barcode. The scoped upsert must not
        // find/update B's row. Since UNIQUE-01 barcode uniqueness is
        // per-business, the insert legitimately creates an A-owned copy
        // instead of colliding with B's row.
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."BC-B,SKU-HIJACK,Hijack Attempt,Umum,1000,1,5\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', $csv),
            ])
            ->assertSessionHas('success');

        $productB->refresh();
        $this->assertSame(9000, (int) $productB->sell_price);
        $this->assertSame('Product B', $productB->title);
        $this->assertSame($this->businessB->id, $productB->business_id);

        // An A-owned copy now exists (same barcode, different owner).
        $copy = Product::withoutGlobalScopes()
            ->where('barcode', 'BC-B')
            ->where('business_id', $this->businessA->id)
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame('Hijack Attempt', $copy->title);
    }

    public function test_import_customers_assigns_active_business_even_with_uploaded_business_id(): void
    {
        $csv = "nama,telepon,alamat,business_id\n"
            ."Customer Import A,08123456789,Jalan A,{$this->businessB->id}\n";

        $this->as($this->userA, $this->businessA)
            ->post(route('import.customers'), [
                'file' => UploadedFile::fake()->createWithContent('customer.csv', $csv),
            ])
            ->assertSessionHas('success');

        // Regression: WithBatchInserts mass-inserts via the query builder and
        // bypasses Eloquent events, so ownership must be assigned explicitly.
        $this->assertDatabaseHas('customers', [
            'name' => 'Customer Import A',
            'business_id' => $this->businessA->id,
        ]);
        $this->assertDatabaseMissing('customers', ['business_id' => null]);
        $this->assertDatabaseMissing('customers', [
            'name' => 'Customer Import A',
            'business_id' => $this->businessB->id,
        ]);
    }

    // 21.10 ---------------------------------------------------- Fail closed

    public function test_import_export_routes_fail_closed_without_active_business(): void
    {
        $outsider = User::factory()->create(['name' => 'No Membership']);
        $outsider->givePermissionTo([
            'products-export',
            'customers-export',
            'transactions-access',
            'products-import',
            'customers-import',
        ]);

        // No membership, no active-business session/header -> no verified context.
        $this->actingAs($outsider)->get(route('export.products'))->assertForbidden();
        $this->actingAs($outsider)->get(route('export.customers'))->assertForbidden();
        $this->actingAs($outsider)->get(route('export.transactions'))->assertForbidden();

        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-NONE,SKU-NONE,No Business,Umum,1000,2000,5\n";

        $this->actingAs($outsider)
            ->post(route('import.products'), ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->post(route('import.customers'), ['file' => UploadedFile::fake()->createWithContent('c.csv', "nama,telepon\nBlocked,08123\n")])
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['barcode' => 'IMP-NONE']);
        $this->assertDatabaseMissing('customers', ['name' => 'Blocked']);
    }

    public function test_product_import_class_fails_closed_without_context(): void
    {
        app(BusinessContext::class)->clear();

        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-GUARD,SKU-GUARD,Guarded,Umum,1000,2000,5\n";

        $threw = false;

        try {
            Excel::import(
                new ProductsImport,
                UploadedFile::fake()->createWithContent('p.csv', $csv),
            );
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'ProductsImport must fail closed without a business context.');
        $this->assertDatabaseMissing('products', ['barcode' => 'IMP-GUARD']);
    }

    public function test_customer_import_class_fails_closed_without_context(): void
    {
        app(BusinessContext::class)->clear();

        $csv = "nama,telepon,alamat\nGuarded Customer,08123,Jalan\n";

        $threw = false;

        try {
            Excel::import(
                new CustomersImport,
                UploadedFile::fake()->createWithContent('c.csv', $csv),
            );
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'CustomersImport must fail closed without a business context.');
        $this->assertDatabaseMissing('customers', ['name' => 'Guarded Customer']);
    }

    // 21.11 -------------------------------------------------- Flag-off legacy

    public function test_legacy_flag_off_preserves_unscoped_export_and_unowned_import(): void
    {
        config()->set('multi_business.enabled', false);
        app(BusinessContext::class)->clear();

        $this->seedProduct($this->businessA, $this->warehouseA, 'BC-A', 'Product A');
        $this->seedProduct($this->businessB, $this->warehouseB, 'BC-B', 'Product B');

        // Legacy mode: global scopes inert -> a legacy operator sees every business.
        $this->assertSame(2, Product::query()->count());

        $legacyUser = User::factory()->create(['name' => 'Legacy']);
        $legacyUser->givePermissionTo(['products-export', 'products-import', 'customers-import']);
        $legacyUser->outlets()->attach($this->outletA->id, ['is_default' => true]);

        $response = $this->actingAs($legacyUser)->get(route('export.products'))->assertOk();
        $content = collect($this->spreadsheetRows($response))->flatten()->implode('|');
        $this->assertStringContainsString('Product A', $content);
        $this->assertStringContainsString('Product B', $content);

        // Legacy import keeps creating unowned rows (no business context exists).
        $csv = "barcode,sku,nama,kategori,harga_beli,harga_jual,stok\n"
            ."IMP-LEGACY,SKU-LEGACY,Produk Legacy,Umum,1000,2000,5\n";

        $this->actingAs($legacyUser)
            ->post(route('import.products'), [
                'file' => UploadedFile::fake()->createWithContent('p.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['barcode' => 'IMP-LEGACY', 'business_id' => null]);

        $customerCsv = "nama,telepon,alamat\nCustomer Legacy,08123,Jalan\n";

        $this->actingAs($legacyUser)
            ->post(route('import.customers'), [
                'file' => UploadedFile::fake()->createWithContent('c.csv', $customerCsv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', ['name' => 'Customer Legacy', 'business_id' => null]);
    }
}
