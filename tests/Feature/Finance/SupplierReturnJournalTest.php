<?php

namespace Tests\Feature\Finance;

use App\Models\Category;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\JournalService;
use App\Services\SupplierReturnService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 1 slice 3: jurnal retur supplier (spec §3.11).
 *
 * Belum lunas: Dr 2100 Hutang Usaha / Cr 1140 Persediaan. Sudah lunas
 * penuh: Dr 1130 Piutang Lainnya / Cr 1140. Sebagian lunas: kedua kaki
 * debit sekaligus, membagi nilai retur antara porsi belum lunas dan
 * porsi yang sudah dibayar. Retur tanpa payable tertaut tidak dijurnal
 * (tidak ada lawan Hutang Usaha).
 */
class SupplierReturnJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Supplier Uji', 'phone' => '081234567890']);
    }

    private function product(int $stock = 100): Product
    {
        $category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(5)),
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Retur Supplier',
            'description' => 'Deskripsi',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => $stock,
            'tax_rate' => 0,
        ]);

        $product->warehouses()->attach($this->warehouse->id, ['stock' => $stock]);

        return $product;
    }

    private function payable(int $total, int $paid): Payable
    {
        return Payable::create([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'DOC-'.Str::upper(Str::random(8)),
            'total' => $total,
            'paid' => $paid,
            'status' => $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
        ]);
    }

    private function draftReturn(Product $product, ?Payable $payable, int $qtyReturned = 40): SupplierReturn
    {
        $return = SupplierReturn::create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payable_id' => $payable?->id,
            'document_number' => 'SR-TEST-'.Str::upper(Str::random(6)),
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $return->items()->create([
            'product_id' => $product->id,
            'qty_returned' => $qtyReturned,
            'unit_price' => 1000,
            'reason' => 'Rusak',
        ]);

        return $return;
    }

    private function complete(SupplierReturn $return): void
    {
        app(SupplierReturnService::class)->complete($return, $this->admin->id);
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

    public function test_unpaid_return_reduces_payable(): void
    {
        $product = $this->product();
        $return = $this->draftReturn($product, $this->payable(100000, 0));

        $this->complete($return);

        $this->assertSame(1, JournalEntry::where('source_type', 'SupplierReturn')->count());
        $entry = JournalEntry::where('source_type', 'SupplierReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['2100', 40000, 0],
            ['1140', 0, 40000],
        ]);
    }

    public function test_fully_paid_return_becomes_claim_on_supplier(): void
    {
        $product = $this->product();
        $return = $this->draftReturn($product, $this->payable(100000, 100000));

        $this->complete($return);

        $entry = JournalEntry::where('source_type', 'SupplierReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['1130', 40000, 0],
            ['1140', 0, 40000],
        ]);
    }

    public function test_partially_paid_return_splits_both_debit_legs(): void
    {
        $product = $this->product();
        $return = $this->draftReturn($product, $this->payable(100000, 80000));

        $this->complete($return);

        // Unpaid portion 20000 reduces the payable; paid portion 20000
        // becomes a claim on the supplier.
        $entry = JournalEntry::where('source_type', 'SupplierReturn')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['2100', 20000, 0],
            ['1130', 20000, 0],
            ['1140', 0, 40000],
        ]);
    }

    public function test_return_without_payable_posts_no_journal(): void
    {
        $product = $this->product();
        $return = $this->draftReturn($product, null);

        $this->complete($return);

        $this->assertSame(0, JournalEntry::where('source_type', 'SupplierReturn')->count());
        $this->assertSame(60, $product->fresh()->stock);
    }

    public function test_repost_is_idempotent(): void
    {
        $product = $this->product();
        $return = $this->draftReturn($product, $this->payable(100000, 0));

        $this->complete($return);

        $first = JournalEntry::where('source_type', 'SupplierReturn')->firstOrFail();
        $second = app(JournalService::class)->postSupplierReturnJournal($return->fresh(), null, 40000, 40000, 0);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'SupplierReturn')->count());
    }
}
