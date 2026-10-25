<?php

namespace Tests\Feature\Finance;

use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\ShiftCashMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingBackfillService;
use App\Services\JournalReportService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 slice 4: backfill command + journal reports.
 *
 * All fixtures are created directly through models (bypassing the live
 * hooks) to simulate pre-slice history. Expected numbers are hand-computed
 * in the test comments; the world is built so every bridge reconciles
 * exactly except the single explained cash gap (a destination-less
 * setoran of Rp1.000).
 *
 * World (all 2026-10-20, no business):
 * - T1 cash paid 10000 (A x5 @2000, buy 1000) -> HPP 5000
 * - T2 pay_later unpaid 6000 (B x4 @1500, buy 500) -> HPP 2000, piutang 6000
 * - T3 pay_later paid 3000 (B x2 @1500, buy 500) -> HPP 1000, piutang lunas
 * - T4 bank paid 4000 (A x2 @2000, buy 1000) -> NOT journaled (tender gap)
 * - E1 drawer Perlengkapan 2000, E2 bank Listrik 1000
 * - Movements: setoran bank 7000, setoran owner 3000, setoran tanpa
 *   tujuan 1000 (gap), modal 50000, setoran_masuk 4000, talangan 4000,
 *   talangan_masuk 4000, lainnya-out 1000, lainnya-in 2000,
 *   pelunasan P2 3000 (+ mirror), pelunasan P3 1000 (+ mirror tanpa
 *   penanda nota), beban E1 2000 (skip, via Expense)
 * - PP-bank transfer 2000 (P2), RP3 cash 3000 (R3)
 * - Shift closed, cash_difference +2000
 * - GR1 receiving 10xA @1000 = 10000 (P2), SR-sup return 2x1000 = 2000
 * - SR1 return T1: refund 2000, restock 1000
 */
class BackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected Product $productA;

    protected Product $productB;

    protected Supplier $supplier;

    protected Customer $customer;

    protected BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $category = Category::create([
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

        $this->productA = Product::create([
            'image' => 'products/a.jpg',
            'barcode' => 'A-001',
            'sku' => 'SKU-A',
            'title' => 'Produk A',
            'description' => 'A',
            'category_id' => $category->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 100,
            'tax_rate' => 0,
        ]);

        $this->productB = Product::create([
            'image' => 'products/b.jpg',
            'barcode' => 'B-001',
            'sku' => 'SKU-B',
            'title' => 'Produk B',
            'description' => 'B',
            'category_id' => $category->id,
            'buy_price' => 500,
            'sell_price' => 1500,
            'stock' => 100,
            'tax_rate' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Penitip Test']);
        $this->customer = Customer::create(['name' => 'Customer Test']);

        $this->bank = BankAccount::create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Toko Test',
            'is_active' => true,
        ]);
    }

    private function setCreatedAt(string $table, int $id, string $datetime): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $datetime, 'updated_at' => $datetime]);
    }

    private function makeTransaction(string $invoice, string $method, string $status, int $grandTotal, array $details, int $cash = 0): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => $invoice,
            'grand_total' => $grandTotal,
            'cash' => $cash,
            'change' => 0,
            'discount' => 0,
            'payment_method' => $method,
            'payment_status' => $status,
        ]);

        foreach ($details as $detail) {
            TransactionDetail::create(array_merge(['transaction_id' => $transaction->id], $detail));
        }

        $this->setCreatedAt('transactions', $transaction->id, '2026-10-20 09:00:00');

        return $transaction;
    }

    private function buildWorld(): void
    {
        $t1 = $this->makeTransaction('INV-T1', 'cash', 'paid', 10000, [
            ['product_id' => $this->productA->id, 'qty' => 5, 'price' => 2000, 'buy_price' => 1000, 'conversion_factor' => 1, 'is_consignment' => false],
        ], 10000);

        $t2 = $this->makeTransaction('INV-T2', 'pay_later', 'unpaid', 6000, [
            ['product_id' => $this->productB->id, 'qty' => 4, 'price' => 1500, 'buy_price' => 500, 'conversion_factor' => 1, 'is_consignment' => false],
        ]);

        Receivable::create([
            'customer_id' => $this->customer->id,
            'transaction_id' => $t2->id,
            'invoice' => 'INV-T2',
            'total' => 6000,
            'paid' => 0,
            'due_date' => '2026-11-20',
            'status' => 'unpaid',
        ]);

        $t3 = $this->makeTransaction('INV-T3', 'pay_later', 'paid', 3000, [
            ['product_id' => $this->productB->id, 'qty' => 2, 'price' => 1500, 'buy_price' => 500, 'conversion_factor' => 1, 'is_consignment' => false],
        ]);

        $receivable3 = Receivable::create([
            'customer_id' => $this->customer->id,
            'transaction_id' => $t3->id,
            'invoice' => 'INV-T3',
            'total' => 3000,
            'paid' => 3000,
            'due_date' => '2026-11-20',
            'status' => 'paid',
        ]);

        ReceivablePayment::create([
            'receivable_id' => $receivable3->id,
            'paid_at' => '2026-10-20',
            'amount' => 3000,
            'method' => 'cash',
            'user_id' => $this->cashier->id,
        ]);

        $this->makeTransaction('INV-T4', 'transfer', 'paid', 4000, [
            ['product_id' => $this->productA->id, 'qty' => 2, 'price' => 2000, 'buy_price' => 1000, 'conversion_factor' => 1, 'is_consignment' => false],
        ]);

        $perlengkapan = ExpenseCategory::create(['name' => 'Perlengkapan']);
        $listrik = ExpenseCategory::create(['name' => 'Listrik & Air']);

        $expense1 = Expense::create([
            'expense_category_id' => $perlengkapan->id,
            'date' => '2026-10-20',
            'amount' => 2000,
            'source' => 'drawer',
            'user_id' => $this->cashier->id,
        ]);

        Expense::create([
            'expense_category_id' => $listrik->id,
            'date' => '2026-10-20',
            'amount' => 3000 - 2000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);

        $shift = CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'closed_by' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'opened_at' => '2026-10-20 08:00:00',
            'closed_at' => '2026-10-20 20:00:00',
            'opening_cash' => 0,
            'cash_difference' => 2000,
            'status' => 'closed',
        ]);

        $movement = function (string $type, string $kind, int $amount, array $extra = []) use ($shift) {
            return ShiftCashMovement::create(array_merge([
                'cashier_shift_id' => $shift->id,
                'type' => $type,
                'kind' => $kind,
                'amount' => $amount,
                'user_id' => $this->cashier->id,
            ], $extra));
        };

        $movement('out', 'setoran', 7000, ['setoran_destination' => 'bank', 'bank_account_id' => $this->bank->id]);
        $movement('out', 'setoran', 3000, ['setoran_destination' => 'owner']);
        $movement('out', 'setoran', 1000, []);
        $movement('in', 'modal', 50000, []);
        $movement('in', 'setoran_masuk', 4000, []);
        $movement('out', 'talangan', 4000, []);
        $movement('in', 'talangan_masuk', 4000, []);
        $movement('out', 'lainnya', 1000, []);
        $movement('in', 'lainnya', 2000, []);

        // Purchase order + receiving + payable P2 (total 8000, paid 5000
        // after the two payments below; return reduces 10000 -> 8000).
        $order = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'PO-TEST-1',
            'status' => 'completed',
            'created_by' => $this->cashier->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty_ordered' => 10,
            'qty_received' => 10,
            'unit_price' => 1000,
        ]);

        $receiving = GoodsReceiving::create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'GR-TEST-1',
            'received_by' => $this->cashier->id,
            'received_at' => '2026-10-20 09:00:00',
        ]);

        GoodsReceivingItem::create([
            'goods_receiving_id' => $receiving->id,
            'purchase_order_item_id' => $poItem->id,
            'product_id' => $this->productA->id,
            'qty_received' => 10,
        ]);

        $this->setCreatedAt('goods_receivings', $receiving->id, '2026-10-20 09:00:00');

        $payable2 = Payable::create([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'GR-TEST-1',
            'total' => 8000,
            'paid' => 5000,
            'status' => 'partial',
            'purchase_order_id' => $order->id,
        ]);

        $payable3 = Payable::create([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'MANUAL-1',
            'total' => 10000,
            'paid' => 1000,
            'status' => 'partial',
        ]);

        $pelunasan = $movement('out', 'pelunasan_hutang', 3000, ['payable_id' => $payable2->id]);
        $this->setCreatedAt('shift_cash_movements', $pelunasan->id, '2026-10-20 10:00:00');

        $mirror = PayablePayment::create([
            'payable_id' => $payable2->id,
            'paid_at' => '2026-10-20',
            'amount' => 3000,
            'method' => 'cash',
            'user_id' => $this->cashier->id,
            'note' => '(dari laci shift #'.$shift->id.')',
        ]);
        $this->setCreatedAt('payable_payments', $mirror->id, '2026-10-20 10:00:00');

        $bankPayment = PayablePayment::create([
            'payable_id' => $payable2->id,
            'paid_at' => '2026-10-20',
            'amount' => 2000,
            'method' => 'transfer',
            'bank_account_id' => $this->bank->id,
            'user_id' => $this->cashier->id,
        ]);
        $this->setCreatedAt('payable_payments', $bankPayment->id, '2026-10-20 10:00:00');

        // Second mirror pair WITHOUT the note marker: the movement match
        // alone must identify it.
        $pelunasan3 = $movement('out', 'pelunasan_hutang', 1000, ['payable_id' => $payable3->id]);
        $this->setCreatedAt('shift_cash_movements', $pelunasan3->id, '2026-10-20 10:00:00');

        $mirror3 = PayablePayment::create([
            'payable_id' => $payable3->id,
            'paid_at' => '2026-10-20',
            'amount' => 1000,
            'method' => 'cash',
            'user_id' => $this->cashier->id,
        ]);
        $this->setCreatedAt('payable_payments', $mirror3->id, '2026-10-20 10:00:00');

        $movement('out', 'beban', 2000, ['expense_id' => $expense1->id]);

        // Supplier return: 2 x 1000 against P2 (unpaid 5000 at return
        // time, so the full 2000 reduces the payable).
        $supplierReturn = SupplierReturn::create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payable_id' => $payable2->id,
            'document_number' => 'SR-SUP-1',
            'status' => 'completed',
            'returned_at' => '2026-10-20 11:00:00',
            'created_by' => $this->cashier->id,
        ]);

        SupplierReturnItem::create([
            'supplier_return_id' => $supplierReturn->id,
            'product_id' => $this->productA->id,
            'qty_returned' => 2,
            'unit_price' => 1000,
        ]);

        $this->setCreatedAt('supplier_returns', $supplierReturn->id, '2026-10-20 11:00:00');

        // Sales return on T1: refund 2000, restock 1 x buy 1000.
        $detailA = TransactionDetail::where('transaction_id', $t1->id)->first();

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-1',
            'transaction_id' => $t1->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'cashier_id' => $this->cashier->id,
            'status' => 'completed',
            'return_type' => 'refund',
            'refund_amount' => 2000,
            'credited_amount' => 0,
            'total_return_amount' => 2000,
            'completed_at' => '2026-10-20 12:00:00',
        ]);

        SalesReturnItem::create([
            'sales_return_id' => $salesReturn->id,
            'transaction_detail_id' => $detailA->id,
            'product_id' => $this->productA->id,
            'qty_sold' => 5,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'return_reason' => 'rusak',
            'restock_to_inventory' => true,
            'unit_price' => 2000,
            'subtotal' => 2000,
        ]);
    }

    public function test_dry_run_posts_nothing(): void
    {
        $this->buildWorld();

        $this->artisan('accounting:backfill', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, JournalEntry::count());
        $this->assertFalse(app(AccountingBackfillService::class)->alreadyCompleted(null));
    }

    public function test_backfill_posts_all_journals_with_expected_counts(): void
    {
        $this->buildWorld();

        $report = app(AccountingBackfillService::class)->run(null, false);

        $this->assertSame(3, $report['types']['sales']['posted']);
        $this->assertSame(['method_not_journaled' => 1], $report['types']['sales']['skipped']);
        $this->assertSame(2, $report['types']['expenses']['posted']);
        $this->assertSame(10, $report['types']['movements']['posted']);
        $this->assertSame(1, $report['types']['payable_payments']['posted']);
        $this->assertSame(2, $report['types']['payable_payments']['skipped']['drawer_mirror']);
        $this->assertSame(1, $report['types']['receivable_payments']['posted']);
        $this->assertSame(1, $report['types']['cash_differences']['posted']);
        $this->assertSame(1, $report['types']['receivings']['posted']);
        $this->assertSame(1, $report['types']['sales_returns']['posted']);
        $this->assertSame(1, $report['types']['supplier_returns']['posted']);
        $this->assertSame(21, JournalEntry::count());

        // Spot-check exact journal lines.
        $t1Entry = JournalEntry::where('source_type', 'Transaction')->where('description', 'like', '%INV-T1%')->first();
        $this->assertNotNull($t1Entry);
        $this->assertSame(10000, (int) $t1Entry->lines->where('account.code', '1100')->first()->debit);

        $setoranOwner = JournalEntry::where('source_type', 'ShiftCashMovement')
            ->whereHas('lines', fn ($query) => $query->whereHas('account', fn ($q) => $q->where('code', '3110')))
            ->first();
        $this->assertNotNull($setoranOwner);
        $this->assertSame(3000, (int) $setoranOwner->lines->where('account.code', '3110')->first()->debit);

        $supplierReturnEntry = JournalEntry::where('source_type', 'SupplierReturn')->first();
        $this->assertNotNull($supplierReturnEntry);
        $this->assertSame(2000, (int) $supplierReturnEntry->lines->where('account.code', '2100')->first()->debit);
        $this->assertSame(2000, (int) $supplierReturnEntry->lines->where('account.code', '1140')->first()->credit);
    }

    public function test_backfill_verification_numbers_are_exact(): void
    {
        $this->buildWorld();

        $report = app(AccountingBackfillService::class)->run(null, false);
        $verification = $report['verification'];

        $this->assertSame(0, $verification['trial_balance']);

        $rec = $verification['reconciliation'];
        $this->assertTrue($rec['balanced']);
        $this->assertSame(0, $rec['residuals']['pendapatan']);
        $this->assertSame(0, $rec['residuals']['hpp']);
        $this->assertSame(0, $rec['residuals']['beban']);
        $this->assertSame(0, $rec['residuals']['selisih_kas']);
        $this->assertSame(0, $rec['residuals']['bersih']);
        $this->assertSame(17000, $rec['journal']['pendapatan']);
        $this->assertSame(17000, $rec['lite']['revenue']);
        $this->assertSame(4000, $rec['bridges']['unjournaled_paid_sales']);
        $this->assertSame(6000, $rec['bridges']['unpaid_credit_sales']);
        $this->assertSame(10000, $rec['journal']['laba_bersih']);
        $this->assertSame(8000, $rec['lite']['net_profit']);

        $cash = $verification['cash_proof'];
        $this->assertSame(51000, $cash['actual_cash']);
        $this->assertSame(52000, $cash['journal_kas']);
        $this->assertSame(-1000, $cash['gap']);
        $this->assertSame(-1000, $cash['explained_gap']);
        $this->assertSame(0, $cash['residual']);
        $this->assertSame(1000, $cash['explained']['setoran_no_destination']);
    }

    public function test_journal_reports_math_and_balance_check(): void
    {
        $this->buildWorld();

        app(AccountingBackfillService::class)->run(null, false);

        $reports = app(JournalReportService::class);

        $ledger = collect($reports->ledgerBalances(null, null, null))->keyBy('code');
        $this->assertSame(52000, $ledger['1100']['closing']);
        $this->assertSame(0, $ledger['1110']['closing']);
        $this->assertSame(6000, $ledger['1120']['closing']);
        $this->assertSame(1000, $ledger['1140']['closing']);

        $pl = $reports->profitLoss(null, null, null);
        $this->assertSame(17000, $pl['pendapatan_penjualan']);
        $this->assertSame(4000, $pl['pendapatan_lainnya']);
        $this->assertSame(7000, $pl['hpp']);
        $this->assertSame(14000, $pl['laba_kotor']);
        $this->assertSame(4000, $pl['beban_total']);
        $this->assertSame(10000, $pl['laba_bersih']);

        $neraca = $reports->balanceSheet(null, null);
        $this->assertSame(59000, $neraca['assets_total']);
        $this->assertSame(2000, $neraca['liabilities_total']);
        $this->assertSame(47000, $neraca['equity_total']);
        $this->assertSame(10000, $neraca['laba_berjalan']);
        $this->assertSame(0, $neraca['check']);
    }

    public function test_command_refuses_second_run_without_force(): void
    {
        $this->buildWorld();

        $this->artisan('accounting:backfill')->assertExitCode(0);
        $this->assertSame(21, JournalEntry::count());

        $this->artisan('accounting:backfill')->assertExitCode(1);
        $this->assertSame(21, JournalEntry::count());

        $this->artisan('accounting:backfill', ['--force' => true])->assertExitCode(0);
        $this->assertSame(21, JournalEntry::count());
    }

    public function test_report_pages_render(): void
    {
        $this->buildWorld();

        app(AccountingBackfillService::class)->run(null, false);

        Permission::firstOrCreate(['name' => 'reports-access', 'guard_name' => 'web']);
        $this->cashier->givePermissionTo('reports-access');

        $this->actingAs($this->cashier)->get(route('reports.ledger.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Reports/Ledger')->has('rows'));

        $this->actingAs($this->cashier)->get(route('reports.journal-profit-loss.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Reports/JournalProfitLoss')->has('summary')->has('reconciliation'));

        $this->actingAs($this->cashier)->get(route('reports.balance-sheet.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Reports/BalanceSheet')->has('summary'));
    }
}
