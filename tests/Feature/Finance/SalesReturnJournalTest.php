<?php

namespace Tests\Feature\Finance;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 1 slice 3: jurnal retur penjualan (spec §3.4).
 *
 * Satu jurnal per retur yang diselesaikan, di transaksi yang sama dengan
 * completion: Dr 4110 / Cr 1100 untuk refund tunai, Dr 1140 / Cr 5100
 * untuk restock barang milik sendiri (snapshot buy_price), Dr 2110 /
 * Cr 5100 untuk pengurang hutang penitip (menggantikan kaki Persediaan),
 * plus kaki Cr 1120 untuk porsi retur kredit yang hanya mengurangi
 * piutang. Porsi store-credit tidak punya akun di bagan fase 1 dan
 * sengaja tidak dijurnal (lihat JournalService::postSalesReturnJournal).
 */
class SalesReturnJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @return array{Transaction, TransactionDetail, Product, ?Customer}
     */
    private function createTransaction(
        User $user,
        int $qty = 1,
        int $stock = 10,
        string $paymentMethod = 'cash',
        bool $withReceivable = false,
        int $receivablePaid = 0,
        bool $isConsignment = false,
        ?Supplier $supplier = null,
    ): array {
        $category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(5)),
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji '.Str::upper(Str::random(4)),
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => $stock,
            'tax_rate' => 0,
        ]);

        $customer = Customer::create([
            'name' => 'Customer Test',
            'no_telp' => '08123456789',
            'address' => 'Jalan Test',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'cashier_shift_id' => null,
            'customer_id' => $customer->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => 60000 * $qty,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 60000 * $qty,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
        ]);

        $detail = $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => $qty,
            'conversion_factor' => 1,
            'price' => 60000,
            'buy_price' => 45000,
            'is_consignment' => $isConsignment,
            'supplier_id' => $supplier?->id,
        ]);

        if ($withReceivable) {
            Receivable::create([
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'invoice' => $transaction->invoice,
                'total' => $transaction->grand_total,
                'paid' => $receivablePaid,
                'due_date' => now()->addDays(7),
                'status' => $receivablePaid > 0 ? 'partial' : 'unpaid',
            ]);
        }

        return [$transaction->fresh(['details', 'receivable']), $detail, $product, $customer];
    }

    private function openShiftFor(User $user): CashierShift
    {
        return CashierShift::create([
            'user_id' => $user->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    private function draftReturn(User $user, Transaction $transaction, Warehouse $warehouse, string $returnType, int $totalReturnAmount): SalesReturn
    {
        return SalesReturn::create([
            'code' => 'SR-TEST-'.Str::upper(Str::random(6)),
            'transaction_id' => $transaction->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'return_type' => $returnType,
            'refund_amount' => 0,
            'credited_amount' => 0,
            'total_return_amount' => $totalReturnAmount,
        ]);
    }

    private function assertJournalLines(JournalEntry $entry, array $expected): void
    {
        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);
        sort($expected);

        $this->assertSame($expected, $lines);
    }

    private function userAndWarehouse(): array
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        $warehouse = Warehouse::create([
            'code' => 'RET',
            'name' => 'Gudang Retur',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [$user, $warehouse];
    }

    public function test_owned_restock_cash_refund_posts_full_journal(): void
    {
        [$user, $warehouse] = $this->userAndWarehouse();
        [$transaction, $detail, $product] = $this->createTransaction($user);
        $this->openShiftFor($user);
        $transaction->update(['warehouse_id' => $warehouse->id]);
        $product->warehouses()->attach($warehouse->id, ['stock' => 10]);

        $salesReturn = $this->draftReturn($user, $transaction, $warehouse, 'refund_cash', 60000);
        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, JournalEntry::where('source_type', 'SalesReturn')->count());
        $entry = JournalEntry::where('source_type', 'SalesReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['4110', 60000, 0],
            ['1100', 0, 60000],
            ['1140', 45000, 0],
            ['5100', 0, 45000],
        ]);
        $this->assertSame(11, $product->fresh()->stock);
    }

    public function test_consignment_return_replaces_inventory_leg_with_payable_leg(): void
    {
        [$user, $warehouse] = $this->userAndWarehouse();
        $supplier = Supplier::create(['name' => 'Penitip Uji', 'phone' => '081234567890']);
        [$transaction, $detail, $product] = $this->createTransaction($user, isConsignment: true, supplier: $supplier);
        $this->openShiftFor($user);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        Payable::create([
            'supplier_id' => $supplier->id,
            'transaction_id' => $transaction->id,
            'document_number' => 'KON-'.$transaction->invoice.'-S'.$supplier->id,
            'total' => 45000,
            'paid' => 0,
            'status' => 'unpaid',
        ]);

        $salesReturn = $this->draftReturn($user, $transaction, $warehouse, 'refund_cash', 60000);
        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Barang titipan dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        $entry = JournalEntry::where('source_type', 'SalesReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['4110', 60000, 0],
            ['1100', 0, 60000],
            ['2110', 45000, 0],
            ['5100', 0, 45000],
        ]);
        $this->assertDatabaseHas('payables', [
            'transaction_id' => $transaction->id,
            'supplier_id' => $supplier->id,
            'total' => 0,
        ]);
    }

    public function test_credit_return_splits_refund_and_receivable_reduction(): void
    {
        [$user, $warehouse] = $this->userAndWarehouse();
        [$transaction, $detail, $product] = $this->createTransaction(
            $user, paymentMethod: 'pay_later', withReceivable: true, receivablePaid: 30000,
        );
        $this->openShiftFor($user);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        $salesReturn = $this->draftReturn($user, $transaction, $warehouse, 'refund_cash', 60000);
        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        // Return 60000 on a receivable paid 30000: 30000 cash refund (the
        // overpaid part) + 30000 receivable reduction.
        $entry = JournalEntry::where('source_type', 'SalesReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['4110', 60000, 0],
            ['1100', 0, 30000],
            ['1120', 0, 30000],
            ['1140', 45000, 0],
            ['5100', 0, 45000],
        ]);
    }

    public function test_store_credit_return_posts_only_restock_legs(): void
    {
        [$user, $warehouse] = $this->userAndWarehouse();
        [$transaction, $detail, $product] = $this->createTransaction($user);
        $this->openShiftFor($user);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        $salesReturn = $this->draftReturn($user, $transaction, $warehouse, 'store_credit', 60000);
        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Tukar saldo toko',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        // Store-credit liability has no phase 1 chart account: only the
        // restock legs post, and the entry still balances.
        $entry = JournalEntry::where('source_type', 'SalesReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['1140', 45000, 0],
            ['5100', 0, 45000],
        ]);
    }

    public function test_repost_is_idempotent(): void
    {
        [$user, $warehouse] = $this->userAndWarehouse();
        [$transaction, $detail, $product] = $this->createTransaction($user);
        $this->openShiftFor($user);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        $salesReturn = $this->draftReturn($user, $transaction, $warehouse, 'refund_cash', 60000);
        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        $first = JournalEntry::where('source_type', 'SalesReturn')->firstOrFail();
        $second = app(JournalService::class)->postSalesReturnJournal(
            $salesReturn->fresh(), null, 60000, 0, 45000, 0,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'SalesReturn')->count());
    }
}
