<?php

namespace Tests\Feature\Business;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\OutletAccessService;
use App\Support\Tenancy\TenantOwnership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-business foreign-key gaps: ids accepted by create/update/checkout must
 * belong to the verified active business, otherwise a member of business A can
 * attach business B's master data (products, warehouses, suppliers, customers,
 * bank accounts) to their own records.
 */
class CrossBusinessForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $admin;

    private Outlet $outletA;

    private Outlet $outletB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Category $categoryA;

    private Category $categoryB;

    private Product $productB;

    private int $supplierBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        config()->set('multi_business.enabled', true);

        $context = app(BusinessContext::class);
        $context->clear();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OA',
            'name' => 'Outlet A',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->outletB = Outlet::create([
            'business_id' => $this->businessB->id,
            'code' => 'OB',
            'name' => 'Outlet B',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'business_id' => $this->businessA->id,
            'outlet_id' => $this->outletA->id,
            'code' => 'WA',
            'name' => 'Warehouse A',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->warehouseB = Warehouse::create([
            'business_id' => $this->businessB->id,
            'outlet_id' => $this->outletB->id,
            'code' => 'WB',
            'name' => 'Warehouse B',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->categoryA = Category::create([
            'business_id' => $this->businessA->id,
            'name' => 'Category A',
            'description' => '-',
        ]);

        $this->categoryB = Category::create([
            'business_id' => $this->businessB->id,
            'name' => 'Category B',
            'description' => '-',
        ]);

        $this->supplierBId = Supplier::create([
            'business_id' => $this->businessB->id,
            'name' => 'Supplier B',
            'phone' => '0800',
            'email' => 'b@example.test',
            'address' => '-',
        ])->id;

        $this->productB = Product::create([
            'business_id' => $this->businessB->id,
            'image' => 'x.png',
            'barcode' => 'PRD-B',
            'sku' => 'SKU-B',
            'title' => 'Product B',
            'description' => '-',
            'category_id' => $this->categoryB->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 5,
            'tax_rate' => 0,
        ]);

        Customer::create([
            'business_id' => $this->businessB->id,
            'name' => 'Customer B',
            'phone' => '0811',
            'email' => 'cust-b@example.test',
            'address' => '-',
        ]);

        $context->clear();
    }

    private function asBusinessA()
    {
        return $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id]);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'barcode' => 'NEW-'.Str::upper(Str::random(8)),
            'sku' => 'NEWSKU-'.Str::upper(Str::random(8)),
            'title' => 'Produk Baru',
            'description' => '-',
            'category_id' => $this->categoryA->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 3,
            'tax_rate' => 0,
        ], $overrides);
    }

    public function test_web_product_create_rejects_foreign_category(): void
    {
        $payload = $this->productPayload(['category_id' => $this->categoryB->id]);

        $this->asBusinessA()
            ->post(route('products.store'), $payload)
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('products', ['barcode' => $payload['barcode']]);
    }

    public function test_web_product_create_rejects_foreign_warehouse(): void
    {
        $payload = $this->productPayload(['warehouse_id' => $this->warehouseB->id]);

        $this->asBusinessA()
            ->post(route('products.store'), $payload)
            ->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseMissing('products', ['barcode' => $payload['barcode']]);
    }

    public function test_web_product_create_accepts_own_category(): void
    {
        $payload = $this->productPayload();

        $this->asBusinessA()
            ->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => $payload['barcode'],
            'business_id' => $this->businessA->id,
        ]);
    }

    public function test_stock_transfer_rejects_foreign_warehouse(): void
    {
        $this->asBusinessA()
            ->post(route('stock-transfers.store'), [
                'source_warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'items' => [],
            ])
            ->assertSessionHasErrors('destination_warehouse_id');
    }

    public function test_purchase_order_rejects_foreign_supplier(): void
    {
        $this->asBusinessA()
            ->post(route('purchase-orders.store'), [
                'supplier_id' => $this->supplierBId,
                'items' => [
                    ['product_id' => $this->productB->id, 'qty_ordered' => 1, 'unit_price' => 1000],
                ],
            ])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_api_product_create_rejects_foreign_category_via_header_context(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->productPayload(['category_id' => $this->categoryB->id]);

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_api_requires_business_context_when_tenancy_enabled(): void
    {
        // Super-admins intentionally fall back to the first active business, so
        // prove the fail-closed path with a non-super member that has more than
        // one membership (no sole membership to auto-resolve) and no explicit
        // session/header selection.
        $member = User::where('email', 'manager@gmail.com')->firstOrFail();
        $member->markEmailAsVerified();

        foreach ([$this->businessA, $this->businessB] as $business) {
            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $member->id,
                'role' => BusinessUser::ROLE_MANAGER,
                'status' => BusinessUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }

        Sanctum::actingAs($member, ['products-access']);

        // No X-Business-Id header and no session -> no context -> fail closed.
        $this->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_api_pos_open_shift_rejects_foreign_warehouse(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/pos/shift/open', [
                'opening_cash' => 100000,
                'warehouse_id' => $this->warehouseB->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');
    }

    public function test_outlet_access_service_hides_other_business_warehouses(): void
    {
        app(BusinessContext::class)->setActive($this->businessA);

        $warehouses = app(OutletAccessService::class)->warehousesFor($this->admin);

        $this->assertTrue($warehouses->contains('id', $this->warehouseA->id));
        $this->assertFalse($warehouses->contains('id', $this->warehouseB->id));
        $this->assertFalse(
            app(OutletAccessService::class)->canUseWarehouse($this->admin, $this->warehouseB)
        );
    }

    public function test_tenant_ownership_resolves_outlet_scoped_bank_accounts(): void
    {
        $accountA = BankAccount::create([
            'outlet_id' => $this->outletA->id,
            'bank_name' => 'Bank A',
            'account_number' => '111',
            'account_name' => 'PT A',
            'is_active' => true,
        ]);

        $accountB = BankAccount::create([
            'outlet_id' => $this->outletB->id,
            'bank_name' => 'Bank B',
            'account_number' => '222',
            'account_name' => 'PT B',
            'is_active' => true,
        ]);

        $ownership = app(TenantOwnership::class);

        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('bank_accounts', $accountA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('bank_accounts', $accountB->id));
        $this->assertTrue($ownership->entityBelongsToActiveBusiness('warehouses', $this->warehouseA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('warehouses', $this->warehouseB->id));
    }

    public function test_tenant_ownership_resolves_warehouse_scoped_purchase_orders(): void
    {
        $poA = PurchaseOrder::create([
            'document_number' => 'PO-A',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseA->id,
        ]);

        $poB = PurchaseOrder::create([
            'document_number' => 'PO-B',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('purchase_orders', $poA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('purchase_orders', $poB->id));
    }

    public function test_goods_receiving_rejects_foreign_purchase_order(): void
    {
        $poB = PurchaseOrder::create([
            'document_number' => 'PO-FOREIGN',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $this->asBusinessA()
            ->post(route('goods-receivings.store'), [
                'purchase_order_id' => $poB->id,
                'items' => [
                    ['purchase_order_item_id' => 1, 'qty_received' => 1],
                ],
            ])
            ->assertSessionHasErrors('purchase_order_id');

        $this->assertDatabaseMissing('goods_receivings', ['purchase_order_id' => $poB->id]);
    }

    public function test_stock_opname_rejects_foreign_warehouse(): void
    {
        $this->asBusinessA()
            ->post(route('stock-opnames.store'), [
                'warehouse_id' => $this->warehouseB->id,
                'notes' => 'x',
            ])
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_legacy_flag_off_preserves_cross_business_behaviour(): void
    {
        config()->set('multi_business.enabled', false);
        app(BusinessContext::class)->clear();

        $payload = $this->productPayload(['category_id' => $this->categoryB->id]);

        $this->actingAs($this->admin)
            ->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['barcode' => $payload['barcode']]);
    }
}
