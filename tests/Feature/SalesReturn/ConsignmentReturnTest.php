<?php

namespace Tests\Feature\SalesReturn;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Payable;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * K2 Konsinyasi: pembalikan hutang saat retur penjualan diselesaikan.
 *
 * Pembalikan mengacu ke SNAPSHOT detail (penitip + status titipan + harga
 * beli saat penjualan), bukan kondisi produk saat ini.
 */
class ConsignmentReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Warehouse $warehouse;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Ibu Ani', 'phone' => '081234567890']);

        CashierShift::create([
            'user_id' => $this->user->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => Category::create([
                'name' => 'Kategori '.Str::upper(Str::random(5)),
                'description' => 'Kategori pengujian',
                'image' => 'category.png',
            ])->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji '.Str::upper(Str::random(4)),
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 2500,
            'sell_price' => 3000,
            'stock' => 100,
            'tax_rate' => 0,
        ], $overrides));
    }

    /**
     * @return array{Transaction, TransactionDetail, Product, Payable}
     */
    private function makeConsignmentSale(int $qty = 10, int $paid = 0): array
    {
        $product = $this->makeProduct([
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->user->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => 3000 * $qty,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 3000 * $qty,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $detail = $transaction->details()->create([
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => true,
            'buy_price' => 2500,
            'conversion_factor' => 1,
            'qty' => $qty,
            'price' => 3000 * $qty,
        ]);

        $payable = Payable::create([
            'supplier_id' => $this->supplier->id,
            'transaction_id' => $transaction->id,
            'document_number' => 'KON-'.$transaction->invoice.'-S'.$this->supplier->id,
            'total' => 2500 * $qty,
            'paid' => $paid,
            'status' => $paid > 0 ? ($paid >= 2500 * $qty ? 'paid' : 'partial') : 'unpaid',
            'note' => 'Konsinyasi dari penjualan '.$transaction->invoice,
        ]);

        return [$transaction, $detail, $product, $payable];
    }

    private function completeReturn(Transaction $transaction, $detail, Product $product, int $qtyReturn): SalesReturn
    {
        $salesReturn = SalesReturn::create([
            'code' => 'SR-'.Str::upper(Str::random(8)),
            'transaction_id' => $transaction->id,
            'warehouse_id' => $this->warehouse->id,
            'cashier_id' => $this->user->id,
            'status' => 'draft',
            'return_type' => 'refund_cash',
            'refund_amount' => 3000 * $qtyReturn,
            'credited_amount' => 0,
            'total_return_amount' => 3000 * $qtyReturn,
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => $detail->qty,
            'qty_returned_before' => 0,
            'qty_return' => $qtyReturn,
            'unit_price' => 3000,
            'subtotal' => 3000 * $qtyReturn,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        return $salesReturn->fresh();
    }

    public function test_partial_return_reduces_payable(): void
    {
        [$transaction, $detail, $product, $payable] = $this->makeConsignmentSale(qty: 10);

        $this->completeReturn($transaction, $detail, $product, 2);

        $payable->refresh();
        $this->assertSame(20000.0, $payable->total);
        $this->assertSame('unpaid', $payable->status);
    }

    public function test_full_return_zeroes_payable(): void
    {
        [$transaction, $detail, $product, $payable] = $this->makeConsignmentSale(qty: 10);

        $this->completeReturn($transaction, $detail, $product, 10);

        $payable->refresh();
        $this->assertSame(0.0, $payable->total);
        $this->assertSame(0.0, $payable->remaining);
        $this->assertStringContainsString('retur', strtolower($payable->note));
    }

    public function test_return_beyond_remaining_floors_at_paid_and_notes_excess(): void
    {
        // Hutang 25000, sudah dibayar 20000 (sisa 5000). Retur 10000:
        // hanya 5000 yang bisa mengurangi, 5000 sisanya dicatat.
        [$transaction, $detail, $product, $payable] = $this->makeConsignmentSale(qty: 10, paid: 20000);

        $this->completeReturn($transaction, $detail, $product, 4);

        $payable->refresh();
        $this->assertSame(20000.0, $payable->total);
        $this->assertSame(20000.0, $payable->paid);
        $this->assertSame('paid', $payable->status);
        $this->assertStringContainsString('5.000', $payable->note);
    }

    public function test_return_of_non_consignment_item_leaves_payable_untouched(): void
    {
        // Transaksi yang sama, penitip yang sama, dua produk: satu titipan
        // (berhutang), satu biasa (tidak berhutang). Retur produk biasa
        // tidak boleh menyentuh hutang produk titipan.
        [$transaction, $detail, $product, $payable] = $this->makeConsignmentSale(qty: 10);

        $plain = $this->makeProduct(['supplier_id' => $this->supplier->id, 'is_consignment' => false]);
        $plainDetail = $transaction->details()->create([
            'product_id' => $plain->id,
            'supplier_id' => $this->supplier->id,
            'is_consignment' => false,
            'buy_price' => 2000,
            'conversion_factor' => 1,
            'qty' => 5,
            'price' => 15000,
        ]);

        $this->completeReturn($transaction, $plainDetail, $plain, 5);

        $payable->refresh();
        $this->assertSame(25000.0, $payable->total);
    }

    public function test_return_still_reverses_after_product_untagged(): void
    {
        [$transaction, $detail, $product, $payable] = $this->makeConsignmentSale(qty: 10);

        // Produk dipetakan ulang sesudah penjualan: bukan titipan lagi.
        // Snapshot detail tetap yang berlaku untuk retur ini.
        $product->update(['is_consignment' => false, 'supplier_id' => null]);

        $this->completeReturn($transaction, $detail, $product, 4);

        $payable->refresh();
        $this->assertSame(15000.0, $payable->total);
    }
}
