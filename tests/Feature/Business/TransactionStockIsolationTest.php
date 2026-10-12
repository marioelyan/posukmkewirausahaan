<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transactional/stock tables (transactions, cashier_shifts, stock_opnames, ...)
 * carry no business_id. Ownership is derived as warehouse -> outlet -> business.
 * With tenancy enabled these must fail closed when ownership cannot be proven.
 */
class TransactionStockIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('multi_business.enabled', true);
        app(BusinessContext::class)->clear();

        $this->businessA = Business::factory()->create();
        $this->businessB = Business::factory()->create();

        $outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'TA',
            'name' => 'Outlet TA',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $outletB = Outlet::create([
            'business_id' => $this->businessB->id,
            'code' => 'TB',
            'name' => 'Outlet TB',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'business_id' => $this->businessA->id,
            'outlet_id' => $outletA->id,
            'code' => 'WTA',
            'name' => 'Warehouse TA',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->warehouseB = Warehouse::create([
            'business_id' => $this->businessB->id,
            'outlet_id' => $outletB->id,
            'code' => 'WTB',
            'name' => 'Warehouse TB',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(BusinessContext::class)->clear();
    }

    private function product(Business $business, Warehouse $warehouse, string $barcode): Product
    {
        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Kat '.$barcode,
            'description' => '-',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'image' => 'x.png',
            'barcode' => $barcode,
            'sku' => 'SKU-'.$barcode,
            'title' => 'Produk '.$barcode,
            'description' => '-',
            'category_id' => $category->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 0,
            'tax_rate' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 0]);

        return $product;
    }

    private function transaction(User $cashier, Warehouse $warehouse, string $invoice): Transaction
    {
        return Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => $invoice,
            'cash' => 2000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 2000,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_tenant_ownership_resolves_transaction_via_warehouse(): void
    {
        $cashier = User::factory()->create();

        $trxA = $this->transaction($cashier, $this->warehouseA, 'INV-TA');
        $trxB = $this->transaction($cashier, $this->warehouseB, 'INV-TB');

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('transactions', $trxA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('transactions', $trxB->id));
    }

    public function test_tenant_ownership_resolves_stock_opname_via_warehouse(): void
    {
        $opnameA = StockOpname::create(['code' => 'SO-TA', 'status' => 'draft', 'warehouse_id' => $this->warehouseA->id]);
        $opnameB = StockOpname::create(['code' => 'SO-TB', 'status' => 'draft', 'warehouse_id' => $this->warehouseB->id]);

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('stock_opnames', $opnameA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('stock_opnames', $opnameB->id));
    }

    public function test_tenant_ownership_resolves_cashier_shift_via_warehouse(): void
    {
        $cashier = User::factory()->create();

        $shiftA = CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
            'warehouse_id' => $this->warehouseA->id,
        ]);

        $shiftB = CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('cashier_shifts', $shiftA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('cashier_shifts', $shiftB->id));
    }

    public function test_product_global_scope_fails_closed_without_context(): void
    {
        $this->product($this->businessA, $this->warehouseA, 'FC-A');
        $this->product($this->businessB, $this->warehouseB, 'FC-B');

        app(BusinessContext::class)->clear();

        $this->assertSame(2, Product::withoutGlobalScopes()->count());
        $this->assertSame(0, Product::query()->count());
    }

    public function test_product_global_scope_isolates_by_active_business(): void
    {
        $productA = $this->product($this->businessA, $this->warehouseA, 'ISO-A');
        $this->product($this->businessB, $this->warehouseB, 'ISO-B');

        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertSame([$productA->id], Product::query()->pluck('id')->all());
    }
}
