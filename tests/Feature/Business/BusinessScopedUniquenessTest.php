<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UNIQUE-01/02/03/05/06/07 (Q4, decision B — per-business).
 *
 * Identifiers owned by a business must be unique within the business but may
 * repeat across businesses. The global unique index is replaced by a composite
 * `(business_id, column)` index once all rows are business-scoped, and the
 * validators use `TenantUnique` so a member of business A can never observe or
 * be blocked by business B's values.
 */
class BusinessScopedUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Category $categoryA;

    private Category $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        config()->set('multi_business.enabled', true);

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->categoryA = $this->categoryFor($this->businessA, 'Category A');
        $this->categoryB = $this->categoryFor($this->businessB, 'Category B');
    }

    public function test_same_barcode_is_allowed_across_businesses_at_db_level(): void
    {
        $this->productFor($this->businessA, $this->categoryA, 'SAME-BARCODE');
        $this->productFor($this->businessB, $this->categoryB, 'SAME-BARCODE');

        $this->assertSame(2, DB::table('products')->where('barcode', 'SAME-BARCODE')->count());
        $this->assertSame(2, DB::table('products')->where('id', '>', 0)->whereNotNull('business_id')->count());
    }

    public function test_duplicate_barcode_is_rejected_within_same_business_at_db_level(): void
    {
        $this->productFor($this->businessA, $this->categoryA, 'DUP-BARCODE');

        $this->expectException(QueryException::class);
        $this->productFor($this->businessA, $this->categoryA, 'DUP-BARCODE');
    }

    public function test_same_warehouse_code_is_allowed_across_businesses_at_db_level(): void
    {
        Warehouse::create([
            'business_id' => $this->businessA->id,
            'code' => 'WH-SAME',
            'name' => 'WH A',
            'type' => 'warehouse',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Warehouse::create([
            'business_id' => $this->businessB->id,
            'code' => 'WH-SAME',
            'name' => 'WH B',
            'type' => 'warehouse',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->assertSame(2, DB::table('warehouses')->where('code', 'WH-SAME')->count());
    }

    public function test_api_products_unique_is_scoped_per_business(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();
        Sanctum::actingAs($admin, ['products-create']);

        $payload = function (int $categoryId, string $barcode) {
            return [
                'barcode' => $barcode,
                'sku' => null,
                'title' => 'Produk',
                'description' => '-',
                'category_id' => $categoryId,
                'buy_price' => 1000,
                'sell_price' => 2000,
                'stock' => 3,
                'tax_rate' => 0,
            ];
        };

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/products', $payload($this->categoryA->id, 'API-CODE'))
            ->assertCreated();

        // Same barcode is fine for the other business.
        $this->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->postJson('/api/v1/products', $payload($this->categoryB->id, 'API-CODE'))
            ->assertCreated();

        // Duplicate within the same business is rejected.
        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/products', $payload($this->categoryA->id, 'API-CODE'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_api_warehouse_code_is_scoped_per_business(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();
        Sanctum::actingAs($admin, ['warehouses-create']);

        $payload = ['code' => 'WH-API', 'name' => 'Gudang', 'type' => 'warehouse'];

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/warehouses', $payload)
            ->assertCreated();

        $this->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->postJson('/api/v1/warehouses', $payload)
            ->assertCreated();

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/warehouses', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    private function categoryFor(Business $business, string $name): Category
    {
        return Category::create([
            'business_id' => $business->id,
            'name' => $name,
            'description' => '-',
        ]);
    }

    private function productFor(Business $business, Category $category, string $barcode): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'image' => 'x.png',
            'barcode' => $barcode,
            'sku' => 'SKU-'.$barcode,
            'title' => 'Product '.$barcode,
            'description' => '-',
            'category_id' => $category->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 5,
            'tax_rate' => 0,
        ]);
    }
}
