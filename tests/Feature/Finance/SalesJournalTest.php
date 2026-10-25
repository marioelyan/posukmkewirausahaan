<?php

namespace Tests\Feature\Finance;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Profit;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutService;
use App\Support\Checkout\CheckoutContext;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fase 1 slice 1: jurnal penjualan dari checkout (§3.1 tunai, §3.2 kredit).
 *
 * HPP memakai snapshot buy_price detail (bukan harga beli saat ini) dan
 * harus sama dengan perhitungan Profit yang sudah ada untuk produk
 * non-komposit.
 */
class SalesJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->category = Category::create([
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

        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            0,
            null,
            $this->warehouse->id,
        );

        BankAccount::create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Toko Test',
            'is_active' => true,
        ]);

        $this->actingAs($this->cashier);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'title' => 'Produk Test '.uniqid(),
            'sku' => 'SKU-JR-'.uniqid(),
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-JR-'.uniqid(),
            'description' => 'Deskripsi produk test',
            'tax_rate' => 0,
            'category_id' => $this->category->id,
        ], $overrides));
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);

        return $product;
    }

    private function makeSupplier(string $name = 'Ibu Ani'): Supplier
    {
        return Supplier::create(['name' => $name, 'phone' => '081234567890']);
    }

    /**
     * @param  array<int, array{Product, int}>  $lines
     */
    private function checkout(array $lines, array $overrides = []): Transaction
    {
        foreach ($lines as [$product, $qty]) {
            $this->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'sell_price' => $product->sell_price,
                'qty' => $qty,
            ]);
        }

        $isPayLater = $overrides['isPayLater'] ?? false;

        $result = app(CheckoutService::class)->execute(new CheckoutContext(
            userId: $this->cashier->id,
            customer: $overrides['customer'] ?? null,
            voucher: null,
            manualDiscount: $overrides['manualDiscount'] ?? 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: $isPayLater,
            dueDate: $overrides['dueDate'] ?? null,
            orderType: null,
            note: null,
            customerNpwp: null,
            isCashPayment: ! $isPayLater,
            cashAmount: $overrides['cashAmount'] ?? 10000000,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        ));

        return $result->transaction;
    }

    private function journalFor(Transaction $transaction): JournalEntry
    {
        return JournalEntry::where('source_type', 'Transaction')
            ->where('source_id', $transaction->id)
            ->firstOrFail();
    }

    /**
     * @return array<string, array{debit: int, credit: int, count: int}>
     */
    private function linesByCode(JournalEntry $entry): array
    {
        $grouped = [];

        foreach ($entry->lines()->with('account')->get() as $line) {
            $code = $line->account->code;
            $grouped[$code] ??= ['debit' => 0, 'credit' => 0, 'count' => 0];
            $grouped[$code]['debit'] += (int) $line->debit;
            $grouped[$code]['credit'] += (int) $line->credit;
            $grouped[$code]['count']++;
        }

        return $grouped;
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $debit = (int) $entry->lines()->sum('debit');
        $credit = (int) $entry->lines()->sum('credit');
        $this->assertSame($credit, $debit, 'Journal entry must be balanced.');
        $this->assertGreaterThan(0, $debit, 'Journal entry must move money.');
    }

    public function test_cash_sale_creates_balanced_journal(): void
    {
        $product = $this->makeProduct();

        $transaction = $this->checkout([[$product, 1]]);

        $this->assertSame(10000, (int) $transaction->grand_total);

        $entry = $this->journalFor($transaction);
        $this->assertBalanced($entry);

        $lines = $this->linesByCode($entry);
        $this->assertSame(['debit' => 10000, 'credit' => 0, 'count' => 1], $lines['1100']);
        $this->assertSame(['debit' => 0, 'credit' => 10000, 'count' => 1], $lines['4100']);
        $this->assertSame(['debit' => 5000, 'credit' => 0, 'count' => 1], $lines['5100']);
        $this->assertSame(['debit' => 0, 'credit' => 5000, 'count' => 1], $lines['1140']);

        // HPP jurnal sama dengan perhitungan Profit yang sudah ada.
        $this->assertSame(5000, (int) Profit::where('transaction_id', $transaction->id)->sum('total'));
    }

    public function test_credit_sale_journals_receivable(): void
    {
        $product = $this->makeProduct();
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '081111111111', 'address' => 'Purwokerto']);

        $transaction = $this->checkout([[$product, 1]], [
            'isPayLater' => true,
            'customer' => $customer,
            'dueDate' => '2026-11-01',
        ]);

        $this->assertSame('pay_later', $transaction->payment_method);
        $this->assertSame(1, Receivable::where('transaction_id', $transaction->id)->count());

        $entry = $this->journalFor($transaction);
        $this->assertBalanced($entry);

        $lines = $this->linesByCode($entry);
        $this->assertSame(['debit' => 10000, 'credit' => 0, 'count' => 1], $lines['1120']);
        $this->assertSame(['debit' => 0, 'credit' => 10000, 'count' => 1], $lines['4100']);
        $this->assertSame(['debit' => 5000, 'credit' => 0, 'count' => 1], $lines['5100']);
        $this->assertSame(['debit' => 0, 'credit' => 5000, 'count' => 1], $lines['1140']);
        $this->assertArrayNotHasKey('1100', $lines, 'Credit sale must not touch Kas.');
    }

    public function test_mixed_owned_and_consignment_sale(): void
    {
        $owned = $this->makeProduct();
        $supplier = $this->makeSupplier();
        $consignment = $this->makeProduct([
            'buy_price' => 3000,
            'sell_price' => 5000,
            'is_consignment' => true,
            'supplier_id' => $supplier->id,
        ]);

        $transaction = $this->checkout([[$owned, 1], [$consignment, 2]]);

        $this->assertSame(20000, (int) $transaction->grand_total);

        // Hutang penitip K2 tetap terbentuk seperti sebelum slice ini.
        $payable = Payable::where('transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(6000, (int) $payable->total);

        $entry = $this->journalFor($transaction);
        $this->assertBalanced($entry);

        $lines = $this->linesByCode($entry);
        $this->assertSame(['debit' => 20000, 'credit' => 0, 'count' => 1], $lines['1100']);
        $this->assertSame(['debit' => 0, 'credit' => 20000, 'count' => 1], $lines['4100']);
        // Dua baris HPP terpisah (milik sendiri + titipan), sesuai spek §3.1.
        $this->assertSame(['debit' => 11000, 'credit' => 0, 'count' => 2], $lines['5100']);
        $this->assertSame(['debit' => 0, 'credit' => 5000, 'count' => 1], $lines['1140']);
        $this->assertSame(['debit' => 0, 'credit' => 6000, 'count' => 1], $lines['2110']);
    }

    public function test_discount_revenue_is_net_of_discount(): void
    {
        $product = $this->makeProduct();

        $transaction = $this->checkout([[$product, 1]], ['manualDiscount' => 2000]);

        $this->assertSame(8000, (int) $transaction->grand_total);

        $entry = $this->journalFor($transaction);
        $this->assertBalanced($entry);

        $lines = $this->linesByCode($entry);
        $this->assertSame(['debit' => 8000, 'credit' => 0, 'count' => 1], $lines['1100']);
        $this->assertSame(['debit' => 0, 'credit' => 8000, 'count' => 1], $lines['4100']);
        // Diskon tidak mengubah HPP.
        $this->assertSame(['debit' => 5000, 'credit' => 0, 'count' => 1], $lines['5100']);
    }

    public function test_hpp_uses_frozen_snapshot_not_current_price(): void
    {
        $product = $this->makeProduct();

        $transaction = $this->checkout([[$product, 1]]);

        // Harga beli diubah SETELAH penjualan: jurnal tidak boleh ikut berubah.
        $product->update(['buy_price' => 9000]);

        $entry = $this->journalFor($transaction);
        $lines = $this->linesByCode($entry);
        $this->assertSame(['debit' => 5000, 'credit' => 0, 'count' => 1], $lines['5100']);
        $this->assertSame(['debit' => 0, 'credit' => 5000, 'count' => 1], $lines['1140']);
    }

    public function test_failed_checkout_creates_no_journal(): void
    {
        $product = $this->makeProduct();

        try {
            $this->checkout([[$product, 1]], ['cashAmount' => 9999]);
            $this->fail('Expected ValidationException for cash below total.');
        } catch (ValidationException $e) {
            $this->assertSame(0, Transaction::count());
            $this->assertSame(0, JournalEntry::count(), 'Failed checkout must not leave a journal.');
        }
    }
}
