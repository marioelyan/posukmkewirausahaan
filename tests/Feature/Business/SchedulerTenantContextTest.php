<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerCampaign;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHED-01 / SCHED-02 — per-business scheduler execution.
 *
 * Scheduled commands run with no HTTP request. `BusinessTaskRunner` enumerates
 * the active businesses and executes the task once per business with an
 * explicit context, always clearing the context afterwards. Without it the
 * tenant-scoped master data (`BelongsToBusiness`) would fail closed and the
 * commands would silently process nothing under tenancy.
 */
class SchedulerTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        config()->set('multi_business.enabled', true);
    }

    public function test_reorder_generate_iterates_each_active_business(): void
    {
        [$businessA, $warehouseA] = $this->businessWithWarehouse();
        [$businessB, $warehouseB] = $this->businessWithWarehouse();

        $this->seedLowStockProduct($businessA, $warehouseA, 'BC-A');
        $this->seedLowStockProduct($businessB, $warehouseB, 'BC-B');

        $this->artisan('reorder:generate')->assertSuccessful();

        // One draft PO per business, scoped to that business's warehouse.
        $this->assertSame(2, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrder::where('warehouse_id', $warehouseA->id)->count());
        $this->assertSame(1, PurchaseOrder::where('warehouse_id', $warehouseB->id)->count());
    }

    public function test_reorder_generate_skips_business_without_warehouses(): void
    {
        Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        [$businessB, $warehouseB] = $this->businessWithWarehouse();
        $this->seedLowStockProduct($businessB, $warehouseB, 'BC-B');

        $this->artisan('reorder:generate')->assertSuccessful();

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, PurchaseOrder::where('warehouse_id', $warehouseB->id)->count());
    }

    public function test_scheduled_run_clears_business_context_afterwards(): void
    {
        [$business, $warehouse] = $this->businessWithWarehouse();
        $this->seedLowStockProduct($business, $warehouse, 'BC-A');

        $this->artisan('reorder:generate')->assertSuccessful();

        $this->assertFalse(app(BusinessContext::class)->hasActiveBusiness());
    }

    public function test_crm_reminders_are_namespaced_and_isolated_per_business(): void
    {
        [$businessA] = $this->businessWithWarehouse();
        [$businessB] = $this->businessWithWarehouse();

        $customerA = $this->seedCustomer($businessA, 'Cust A', '62811111111');
        $customerB = $this->seedCustomer($businessB, 'Cust B', '62822222222');
        $this->seedOverdueReceivable($businessA, $customerA, 'INV-A');
        $this->seedOverdueReceivable($businessB, $customerB, 'INV-B');

        $this->artisan('crm:generate-reminders')->assertSuccessful();

        $today = now()->toDateString();
        $keyA = 'overdue-'.$businessA->id.'-'.$today;
        $keyB = 'overdue-'.$businessB->id.'-'.$today;

        // Context is cleared once the run is finished, so these verification
        // reads must bypass the fail-closed business scope on purpose.
        $campaignA = CustomerCampaign::withoutGlobalScope('business')->where('context_key', $keyA)->first();
        $campaignB = CustomerCampaign::withoutGlobalScope('business')->where('context_key', $keyB)->first();

        $this->assertNotNull($campaignA);
        $this->assertNotNull($campaignB);
        $this->assertNotSame($campaignA->id, $campaignB->id);

        // Each business's campaign only ever references its own customers.
        $this->assertSame([$customerA->id], $campaignA->logs()->pluck('customer_id')->all());
        $this->assertSame([$customerB->id], $campaignB->logs()->pluck('customer_id')->all());

        // Context is cleared once the run is finished.
        $this->assertFalse(app(BusinessContext::class)->hasActiveBusiness());
    }

    private function businessWithWarehouse(): array
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $outlet = Outlet::create([
            'business_id' => $business->id,
            'code' => 'O'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Outlet',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $warehouse = Warehouse::create([
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
            'code' => 'W'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Warehouse',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [$business, $warehouse];
    }

    private function seedLowStockProduct(Business $business, Warehouse $warehouse, string $barcode): Product
    {
        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Cat '.substr(uniqid(), -4),
            'description' => '-',
        ]);

        $product = Product::create([
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
            'min_stock' => 10,
            'max_stock' => 20,
            'tax_rate' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 2]);

        return $product;
    }

    private function seedCustomer(Business $business, string $name, string $noTelp): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'name' => $name,
            'no_telp' => $noTelp,
            'address' => '-',
        ]);
    }

    private function seedOverdueReceivable(Business $business, Customer $customer, string $invoice): void
    {
        Receivable::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'invoice' => $invoice,
            'total' => 50000,
            'paid' => 0,
            'due_date' => now()->subDays(3),
            'status' => 'unpaid',
            'ownership_status' => 'resolved',
        ]);
    }
}
