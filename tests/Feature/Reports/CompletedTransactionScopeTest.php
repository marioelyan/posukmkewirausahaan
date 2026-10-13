<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Q1 (register §13.25): completed === payment_status 'paid'.
 *
 * Revenue, profit, and sales-count reporting must count only settled (paid)
 * transactions. `unpaid` (credit), `pending`, `pending_approval`, and `failed`
 * must be excluded from dashboard, sales/profit/insights reports, and member
 * revenue, while remaining visible in activity feeds.
 */
class CompletedTransactionScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Category $category;

    private Product $product;

    private Customer $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->category = Category::create([
            'name' => 'Kategori Uji',
            'image' => '',
            'description' => '-',
        ]);

        $this->product = Product::create([
            'image' => '',
            'description' => '-',
            'title' => 'Produk Uji',
            'sku' => 'SKU-Q1',
            'barcode' => 'BC-Q1',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 100,
            'tax_rate' => 0,
            'category_id' => $this->category->id,
        ]);

        $this->warehouse->products()->attach($this->product->id, ['stock' => 100]);

        $this->member = Customer::create([
            'name' => 'Member Uji',
            'no_telp' => '628123456789',
            'address' => '-',
            'is_loyalty_member' => true,
            'loyalty_tier' => 'gold',
        ]);
    }

    private function makeTransaction(string $paymentStatus, int $grandTotal, int $profitTotal, int $qty = 1): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'customer_id' => $this->member->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'INV-'.strtoupper($paymentStatus).'-'.$grandTotal,
            'cash' => $grandTotal,
            'change' => 0,
            'discount' => 0,
            'grand_total' => $grandTotal,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
        ]);

        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => $qty,
            'base_unit_price' => 2000,
            'unit_price' => (int) round($grandTotal / $qty),
            'price' => $grandTotal,
        ]);

        $transaction->profits()->create(['total' => $profitTotal]);

        return $transaction;
    }

    private function seedMixedStatuses(): void
    {
        $this->makeTransaction('paid', 100_000, 40_000, 2);
        $this->makeTransaction('unpaid', 200_000, 80_000, 3);
        $this->makeTransaction('pending', 300_000, 120_000, 4);
        $this->makeTransaction('pending_approval', 400_000, 160_000, 5);
        $this->makeTransaction('failed', 500_000, 200_000, 6);
    }

    public function test_completed_scope_matches_only_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->assertSame(1, Transaction::completed()->count());
        $this->assertSame(100_000, (int) Transaction::completed()->sum('grand_total'));
    }

    public function test_dashboard_analytics_exclude_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalTransactions', 1)
                ->where('totalRevenue', 100_000)
                ->where('totalProfit', 40_000)
                ->where('averageOrder', 100_000)
                ->where('todayTransactions', 1)
                ->where('todaySales', 100_000)
                ->where('todayProfit', 40_000)
                ->where('currentMonthSales', 100_000)
            );
    }

    public function test_sales_report_excludes_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->actingAs($this->admin)
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.orders_count', 1)
                ->where('summary.revenue_total', 100_000)
                ->where('summary.items_sold', 2)
                ->has('transactions.data', 1)
                ->where('transactions.data.0.invoice', 'INV-PAID-100000')
            );
    }

    public function test_profit_report_excludes_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->actingAs($this->admin)
            ->get(route('reports.profits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.orders_count', 1)
                ->where('summary.revenue_total', 100_000)
                ->where('summary.profit_total', 40_000)
                ->has('transactions.data', 1)
                ->where('transactions.data.0.invoice', 'INV-PAID-100000')
            );
    }

    public function test_insights_report_excludes_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->actingAs($this->admin)
            ->get(route('reports.insights.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.orders_count', 1)
                ->where('summary.revenue_total', 100_000)
                ->where('summary.items_sold', 2)
                ->where('summary.profit_total', 40_000)
            );
    }

    public function test_member_revenue_excludes_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        $this->actingAs($this->admin)
            ->get(route('members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.member_revenue', 100_000)
            );
    }

    public function test_activity_feed_still_shows_non_paid_transactions(): void
    {
        $this->seedMixedStatuses();

        // Recent transactions is an operational activity feed, not a revenue
        // metric, so it must keep listing non-paid rows.
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentTransactions', 5)
            );
    }
}
