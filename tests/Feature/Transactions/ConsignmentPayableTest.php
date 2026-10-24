<?php

namespace Tests\Feature\Transactions;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutService;
use App\Support\Checkout\CheckoutContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * K2 Konsinyasi: hutang otomatis saat checkout.
 *
 * Skema terkunci: hutang = qty laku x buy_price (per unit dasar).
 * Satu hutang per penitip per transaksi, dalam transaksi DB yang sama
 * dengan penjualan.
 */
class ConsignmentPayableTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

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
            'sku' => 'SKU-CO-'.uniqid(),
            'buy_price' => 2500,
            'sell_price' => 3000,
            'stock' => 100,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-CO-'.uniqid(),
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

    /** Checkout tunai lewat service, kas besar supaya tidak terhalang validasi uang. */
    private function checkout(array $lines): Transaction
    {
        foreach ($lines as [$product, $qty]) {
            $this->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'sell_price' => $product->sell_price,
                'qty' => $qty,
            ]);
        }

        $result = app(CheckoutService::class)->execute(new CheckoutContext(
            userId: $this->cashier->id,
            customer: null,
            voucher: null,
            manualDiscount: 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: false,
            dueDate: null,
            orderType: null,
            note: null,
            customerNpwp: null,
            isCashPayment: true,
            cashAmount: 10000000,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        ));

        return $result->transaction;
    }

    public function test_checkout_creates_payable_for_consignment_product(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true]);

        $transaction = $this->checkout([[$product, 4]]);

        $payable = Payable::where('transaction_id', $transaction->id)->first();
        $this->assertNotNull($payable);
        $this->assertSame($supplier->id, $payable->supplier_id);
        $this->assertSame(10000.0, $payable->total);
        $this->assertSame(0.0, $payable->paid);
        $this->assertSame('unpaid', $payable->status);
        $this->assertSame('KON-'.$transaction->invoice.'-S'.$supplier->id, $payable->document_number);
        $this->assertStringContainsString($transaction->invoice, $payable->note);
    }

    public function test_mixed_cart_only_accrues_consignment_lines(): void
    {
        $supplier = $this->makeSupplier();
        $titipan = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true]);
        $biasa = $this->makeProduct();

        $transaction = $this->checkout([[$titipan, 2], [$biasa, 5]]);

        $this->assertSame(1, Payable::where('transaction_id', $transaction->id)->count());
        $this->assertSame(5000.0, Payable::where('transaction_id', $transaction->id)->first()->total);
    }

    public function test_same_supplier_products_aggregate_into_one_payable(): void
    {
        $supplier = $this->makeSupplier();
        $p1 = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true, 'buy_price' => 2500]);
        $p2 = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true, 'buy_price' => 1000]);

        $transaction = $this->checkout([[$p1, 4], [$p2, 3]]);

        $payables = Payable::where('transaction_id', $transaction->id)->get();
        $this->assertCount(1, $payables);
        $this->assertSame(13000.0, $payables->first()->total);
    }

    public function test_different_suppliers_get_separate_payables(): void
    {
        $s1 = $this->makeSupplier('Ibu Ani');
        $s2 = $this->makeSupplier('Pak Budi');
        $p1 = $this->makeProduct(['supplier_id' => $s1->id, 'is_consignment' => true, 'buy_price' => 2500]);
        $p2 = $this->makeProduct(['supplier_id' => $s2->id, 'is_consignment' => true, 'buy_price' => 2000]);

        $transaction = $this->checkout([[$p1, 2], [$p2, 3]]);

        $payables = Payable::where('transaction_id', $transaction->id)->orderBy('supplier_id')->get();
        $this->assertCount(2, $payables);
        $this->assertSame(5000.0, $payables->firstWhere('supplier_id', $s1->id)->total);
        $this->assertSame(6000.0, $payables->firstWhere('supplier_id', $s2->id)->total);
    }

    public function test_product_with_supplier_but_not_flagged_creates_no_payable(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => false]);

        $transaction = $this->checkout([[$product, 5]]);

        $this->assertSame(0, Payable::where('transaction_id', $transaction->id)->count());
    }

    public function test_zero_buy_price_creates_no_payable(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true, 'buy_price' => 0]);

        $transaction = $this->checkout([[$product, 5]]);

        $this->assertSame(0, Payable::where('transaction_id', $transaction->id)->count());
    }

    public function test_conversion_factor_scales_payable_to_base_units(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true, 'buy_price' => 2500]);

        // Keranjang langsung (bukan lewat route) supaya conversion_factor bisa diatur.
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'qty' => 3,
            'price' => $product->sell_price,
            'conversion_factor' => 2,
        ]);

        $transaction = $this->checkout([]);

        // 3 (satuan jual) x 2 (konversi) x 2500 = 15000.
        $this->assertSame(15000.0, Payable::where('transaction_id', $transaction->id)->first()->total);
    }

    public function test_failed_checkout_creates_no_payable(): void
    {
        $supplier = $this->makeSupplier();
        $titipan = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true]);
        $kosong = $this->makeProduct(['stock' => 0]);
        $this->warehouse->products()->updateExistingPivot($kosong->id, ['stock' => 0]);

        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $titipan->id,
            'qty' => 1,
            'price' => $titipan->sell_price,
            'conversion_factor' => 1,
        ]);
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $kosong->id,
            'qty' => 1,
            'price' => $kosong->sell_price,
            'conversion_factor' => 1,
        ]);

        try {
            $this->checkout([]);
            $this->fail('Expected checkout to fail on insufficient stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Payable::count());
    }

    public function test_detail_snapshots_sale_time_consignment_state(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(['supplier_id' => $supplier->id, 'is_consignment' => true, 'buy_price' => 2500]);

        $transaction = $this->checkout([[$product, 2]]);

        $detail = $transaction->details()->first();
        $this->assertSame($supplier->id, $detail->supplier_id);
        $this->assertTrue($detail->is_consignment);
        $this->assertSame(2500, $detail->buy_price);

        // Harga beli produk diubah sesudah penjualan: snapshot tidak ikut berubah.
        $product->update(['buy_price' => 9999]);
        $this->assertSame(2500, $detail->fresh()->buy_price);
    }
}
