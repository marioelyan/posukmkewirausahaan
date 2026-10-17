<?php

namespace Tests\Feature\Business;

use App\Exports\TransactionsExport;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerCampaign;
use App\Models\CustomerCampaignLog;
use App\Models\LoyaltyPointHistory;
use App\Models\Outlet;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\OutletAccessService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Build 4A — Reports & Analytics isolation.
 *
 * With tenancy enabled, a request operating under Business A must never read,
 * aggregate, infer, or export data belonging to Business B. Datasets share
 * overlapping dates so isolation cannot pass merely because rows differ in time.
 */
class ReportIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_DATE = '2026-06-15 10:00:00';

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
        $user->givePermissionTo(['reports-access', 'profits-access', 'transactions-access', 'dashboard-access']);
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

    /**
     * @return array{category: Category, product: Product, customer: Customer, cashier: User, transaction: Transaction}
     */
    private function seedBusinessData(
        Business $business,
        Warehouse $warehouse,
        string $tag,
        int $grandTotal,
        int $buyPrice = 1000,
        int $qty = 1,
        ?Carbon $at = null,
    ): array {
        $at ??= Carbon::parse(self::SHARED_DATE);

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Category '.$tag,
            'description' => '-',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'image' => 'x.png',
            'barcode' => 'BC-'.$tag,
            'sku' => 'SKU-'.$tag,
            'title' => 'Product '.$tag,
            'description' => '-',
            'category_id' => $category->id,
            'buy_price' => $buyPrice,
            'sell_price' => 2000,
            'stock' => 100,
            'tax_rate' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 100]);

        $customer = Customer::create([
            'business_id' => $business->id,
            'name' => 'Customer '.$tag,
            'no_telp' => '6280000000'.$qty,
            'address' => '-',
        ]);

        $cashier = User::factory()->create(['name' => 'Cashier '.$tag]);

        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'invoice' => 'INV-'.$tag,
            'cash' => $grandTotal,
            'change' => 0,
            'discount' => 0,
            'grand_total' => $grandTotal,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $transaction->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        $detail = $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => $qty,
            'base_unit_price' => 2000,
            'unit_price' => (int) round($grandTotal / $qty),
            'price' => $grandTotal,
        ]);
        $detail->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        $profit = $transaction->profits()->create([
            'total' => $grandTotal - ($buyPrice * $qty),
        ]);
        $profit->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        return compact('category', 'product', 'customer', 'cashier', 'transaction');
    }

    private function props(Assert $page): array
    {
        return $page->toArray()['props'];
    }

    // 9.1 ---------------------------------------------------------------- Sales

    public function test_sales_report_only_contains_active_business_data(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertSame(20_000, $props['summary']['revenue_total']);
                $this->assertTrue($invoices->contains('INV-A'));
                $this->assertFalse($invoices->contains('INV-B'));
            });
    }

    public function test_sales_report_scopes_for_business_b_too(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $this->actingAs($this->userB)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('reports.sales.index'))
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertSame(75_000, $props['summary']['revenue_total']);
                $this->assertTrue($invoices->contains('INV-B'));
                $this->assertFalse($invoices->contains('INV-A'));
            });
    }

    // 9.2 --------------------------------------------------------------- Profit

    public function test_profit_report_only_contains_active_business_data(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000, 1_000, 1);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000, 1_000, 1);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.profits.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertSame(20_000, $props['summary']['revenue_total']);
                $this->assertSame(19_000, $props['summary']['profit_total']);
                $this->assertTrue($invoices->contains('INV-A'));
                $this->assertFalse($invoices->contains('INV-B'));
            });
    }

    // 9.3 ------------------------------------------------------------- Insights

    public function test_insights_metrics_are_business_scoped(): void
    {
        $a = $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000, 1_000, 2);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000, 1_000, 5);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            // The seeded rows sit outside the page's default 30-day window, and
            // this test is about tenant scoping, so query all time on purpose.
            ->get(route('reports.insights.index', ['all_time' => 1]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);

                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertSame(20_000, $props['summary']['revenue_total']);
                $this->assertSame(2, $props['summary']['items_sold']);

                $titles = collect($props['topSellingProducts'])->pluck('product_title');
                $this->assertTrue($titles->contains('Product A'));
                $this->assertFalse($titles->contains('Product B'));

                $categories = collect($props['marginByCategory'])->pluck('category_name');
                $this->assertTrue($categories->contains('Category A'));
                $this->assertFalse($categories->contains('Category B'));

                $days = collect($props['salesByDay']);
                $this->assertSame(1, $days->count());
                $this->assertSame(20_000, $days->first()['revenue_total']);

                $hourly = collect($props['salesByHour'])->sum('revenue_total');
                $this->assertSame(20_000, (int) $hourly);

                $cashiers = collect($props['cashierPerformance'])->pluck('cashier_name');
                $this->assertTrue($cashiers->contains('Cashier A'));
                $this->assertFalse($cashiers->contains('Cashier B'));

                $this->assertSame(1, $props['repeatCustomerMetrics']['summary']['active_customers']);
                $this->assertSame(20_000, $props['repeatCustomerMetrics']['summary']['non_member_revenue_total']);

                $coverageTitles = collect($props['stockCoverage']['products'])->pluck('product_title');
                $this->assertTrue($coverageTitles->contains('Product A'));
                $this->assertFalse($coverageTitles->contains('Product B'));
            });
    }

    public function test_insights_crm_loyalty_and_promo_panels_do_not_leak_other_business(): void
    {
        // Business A owns an outlet-scoped promo + campaign and a loyalty member.
        PricingRule::create([
            'name' => 'Promo A',
            'outlet_id' => $this->outletA->id,
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'is_active' => true,
            'priority' => 10,
            'target_type' => PricingRule::TARGET_ALL,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 5,
        ]);
        // Unowned promo (no outlet) must not surface while tenancy is enabled.
        PricingRule::create([
            'name' => 'Unowned Promo',
            'outlet_id' => null,
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'is_active' => true,
            'priority' => 5,
            'target_type' => PricingRule::TARGET_ALL,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 5,
        ]);
        // Business B promo must never surface under Business A.
        PricingRule::create([
            'name' => 'Promo B',
            'outlet_id' => $this->outletB->id,
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'is_active' => true,
            'priority' => 10,
            'target_type' => PricingRule::TARGET_ALL,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 5,
        ]);

        AuditLog::create([
            'event' => 'pricing_rule.created',
            'module' => 'pricing_rules',
            'description' => 'Promo A dibuat.',
            'created_at' => now(),
        ]);

        $memberA = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'Member A',
            'no_telp' => '62811110000',
            'address' => '-',
            'is_loyalty_member' => true,
            'loyalty_tier' => 'gold',
            'loyalty_points' => 40,
        ]);
        Customer::create([
            'business_id' => $this->businessB->id,
            'name' => 'Member B',
            'no_telp' => '62822220000',
            'address' => '-',
            'is_loyalty_member' => true,
            'loyalty_tier' => 'gold',
            'loyalty_points' => 999,
        ]);

        LoyaltyPointHistory::create([
            'customer_id' => $memberA->id,
            'type' => LoyaltyPointHistory::TYPE_EARN,
            'points_delta' => 40,
            'balance_after' => 40,
            'amount_delta' => 0,
        ]);

        $campaignA = CustomerCampaign::create([
            'name' => 'Campaign A',
            'outlet_id' => $this->outletA->id,
            'business_id' => $this->businessA->id,
            'type' => CustomerCampaign::TYPE_PROMO_BROADCAST,
            'status' => CustomerCampaign::STATUS_READY,
            'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
        ]);
        CustomerCampaign::create([
            'name' => 'Campaign B',
            'outlet_id' => $this->outletB->id,
            'business_id' => $this->businessB->id,
            'type' => CustomerCampaign::TYPE_PROMO_BROADCAST,
            'status' => CustomerCampaign::STATUS_READY,
            'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
        ]);
        CustomerCampaignLog::create([
            'customer_campaign_id' => $campaignA->id,
            'customer_id' => $memberA->id,
            'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
            'status' => CustomerCampaignLog::STATUS_READY_TO_SEND,
        ]);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.insights.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);

                $this->assertSame(1, $props['promoMonitor']['summary']['active']);
                $names = collect($props['promoMonitor']['active_rules'])->pluck('name');
                $this->assertTrue($names->contains('Promo A'));
                $this->assertFalse($names->contains('Promo B'));
                $this->assertFalse($names->contains('Unowned Promo'));
                $this->assertSame([], $props['promoMonitor']['recent_audits']);

                $this->assertSame(1, $props['loyaltyPerformance']['summary']['total_members']);
                $this->assertSame(40, $props['loyaltyPerformance']['summary']['points_earned']);
                $this->assertSame('Member A', $props['loyaltyPerformance']['top_members'][0]['name']);

                // Segments have no provable owner → fail closed under tenancy.
                $this->assertSame(0, $props['crmOperations']['summary']['segments_total']);
                $this->assertSame(1, $props['crmOperations']['summary']['campaigns_total']);
                $this->assertSame(1, $props['crmOperations']['summary']['queue_ready_to_send']);
                $campaignNames = collect($props['crmOperations']['recent_campaigns'])->pluck('name');
                $this->assertTrue($campaignNames->contains('Campaign A'));
                $this->assertFalse($campaignNames->contains('Campaign B'));
            });
    }

    // 9.4 / 9.5 -------------------------------------------- Filter isolation

    public function test_sales_report_ignores_foreign_warehouse_filter(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.sales.index', ['warehouse_id' => $this->warehouseB->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertNull($props['filters']['warehouse_id']);
                $this->assertFalse($invoices->contains('INV-B'));
                $this->assertSame(20_000, $props['summary']['revenue_total']);
            });
    }

    public function test_profit_report_ignores_foreign_warehouse_filter(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.profits.index', ['warehouse_id' => $this->warehouseB->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertNull($props['filters']['warehouse_id']);
                $this->assertFalse($invoices->contains('INV-B'));
                $this->assertSame(20_000, $props['summary']['revenue_total']);
            });
    }

    public function test_session_active_outlet_from_other_business_cannot_widen_scope(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $this->actingAs($this->userA)
            ->withSession([
                'active_business_id' => $this->businessA->id,
                'active_outlet_id' => $this->outletB->id,
            ])
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $invoices = collect($props['transactions']['data'])->pluck('invoice');

                $this->assertFalse($invoices->contains('INV-B'));
                $this->assertTrue($invoices->contains('INV-A'));
            });
    }

    // 9.6 --------------------------------------------------- Date isolation

    public function test_overlapping_dates_do_not_merge_business_totals(): void
    {
        // Same timestamp for both businesses: a per-business filter must still
        // separate them, not rely on disjoint dates.
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 33_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 44_000);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.sales.index', [
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-15',
            ]))
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertSame(33_000, $props['summary']['revenue_total']);
            });
    }

    // 9.7 ------------------------------------------------------ Fail closed

    public function test_reports_fail_closed_without_business_context(): void
    {
        $outsider = User::factory()->create();
        $outsider->givePermissionTo(['reports-access', 'profits-access']);

        $this->actingAs($outsider)->get(route('reports.sales.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('reports.profits.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('reports.insights.index'))->assertForbidden();
    }

    public function test_warehouse_scope_fails_closed_without_context(): void
    {
        app(BusinessContext::class)->clear();

        $this->assertTrue(
            app(OutletAccessService::class)->warehousesFor($this->userA)->isEmpty(),
            'Warehouse scope must return nothing without a verified business context.',
        );
    }

    // 9.8 --------------------------------------- Cross-business aggregation

    public function test_aggregate_totals_exclude_other_business(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 12_345);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 98_765);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('reports.sales.index'))
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);

                $this->assertSame(12_345, $props['summary']['revenue_total']);
                $this->assertSame(1, $props['summary']['orders_count']);
                $this->assertNotSame(111_110, $props['summary']['revenue_total']);
            });

        $this->actingAs($this->userB)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('reports.sales.index'))
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);
                $this->assertSame(98_765, $props['summary']['revenue_total']);
            });
    }

    // Exports -----------------------------------------------------------

    public function test_dashboard_analytics_are_business_scoped(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 12_345);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 98_765);

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $this->props($page);

                $this->assertSame(1, $props['totalTransactions']);
                $this->assertSame(12_345, $props['totalRevenue']);
                $this->assertNotSame(111_110, $props['totalRevenue']);

                $products = collect($props['topProducts'])->pluck('name');
                $this->assertTrue($products->contains('Product A'));
                $this->assertFalse($products->contains('Product B'));

                $this->assertTrue($props['setupChecklist']['transaction']);
            });
    }

    public function test_transaction_export_is_business_scoped_for_super_admin(): void
    {
        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        $superAdmin = User::factory()->create(['name' => 'Super Admin']);
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $superAdmin->givePermissionTo('transactions-access');

        Excel::fake();

        $this->actingAs($superAdmin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('export.transactions'))
            ->assertOk();

        Excel::assertDownloaded('transaksi.xlsx', function (TransactionsExport $export) {
            $invoices = $export->collection()->pluck('invoice');

            return $invoices->contains('INV-A') && ! $invoices->contains('INV-B');
        });
    }

    // 10 ---------------------------------------------------- Flag-off legacy

    public function test_legacy_mode_reports_remain_unscoped(): void
    {
        config()->set('multi_business.enabled', false);
        app(BusinessContext::class)->clear();

        $this->seedBusinessData($this->businessA, $this->warehouseA, 'A', 20_000);
        $this->seedBusinessData($this->businessB, $this->warehouseB, 'B', 75_000);

        // Business global scopes must be inert in legacy mode.
        $this->assertSame(2, Product::query()->count());

        $superAdmin = User::factory()->create(['name' => 'Legacy Admin']);
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $superAdmin->givePermissionTo(['reports-access', 'profits-access']);

        // No business context is required, and outlets from either business are
        // reachable exactly as before Build 4A.
        $this->actingAs($superAdmin)
            ->withSession(['active_outlet_id' => $this->outletA->id])
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $invoices = collect($this->props($page)['transactions']['data'])->pluck('invoice');
                $this->assertTrue($invoices->contains('INV-A'));
            });

        $this->actingAs($superAdmin)
            ->withSession(['active_outlet_id' => $this->outletB->id])
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $invoices = collect($this->props($page)['transactions']['data'])->pluck('invoice');
                $this->assertTrue($invoices->contains('INV-B'));
            });
    }
}
