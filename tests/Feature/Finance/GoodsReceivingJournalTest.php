<?php

namespace Tests\Feature\Finance;

use App\Models\Category;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceivingService;
use App\Services\JournalService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 1 slice 3: jurnal penerimaan barang (spec §3.5).
 *
 * Dr 1140 Persediaan / Cr 2100 Hutang Usaha untuk nilai yang diterima
 * pada receiving itu. Partial receiving menjurnal nilainya masing-masing;
 * receiving tanpa supplier tidak punya lawan Hutang Usaha dan tidak
 * dijurnal.
 */
class GoodsReceivingJournalTest extends TestCase
{
    use RefreshDatabase;

    protected static int $seq = 0;

    protected User $admin;

    protected Category $category;

    protected Warehouse $warehouse;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(5)),
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Supplier Uji', 'phone' => '081234567890']);
    }

    private function createProduct(int $stock = 100): Product
    {
        self::$seq++;

        $product = Product::create([
            'title' => 'Produk '.self::$seq,
            'sku' => 'SKU-PO-'.self::$seq,
            'buy_price' => 10000,
            'sell_price' => 15000,
            'stock' => $stock,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-PO-'.self::$seq,
            'description' => 'Deskripsi produk test',
            'tax_rate' => 0,
            'category_id' => $this->category->id,
        ]);

        $product->warehouses()->attach($this->warehouse->id, ['stock' => $stock]);

        return $product;
    }

    private function createOrderedPo(Product $product, int $qty = 10): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        $order = $service->createOrder(
            data: [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
            ],
            items: [[
                'product_id' => $product->id,
                'qty_ordered' => $qty,
                'unit_price' => 5000,
            ]],
            userId: $this->admin->id
        );

        $service->placeOrder($order);

        return $order->fresh();
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

    public function test_partial_receivings_journal_their_own_values(): void
    {
        $product = $this->createProduct();
        $order = $this->createOrderedPo($product, 10);
        $service = app(GoodsReceivingService::class);

        $first = $service->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'qty_received' => 4,
        ]], null, $this->admin->id);

        $second = $service->receive($order->fresh(), [[
            'purchase_order_item_id' => $order->items->first()->id,
            'qty_received' => 6,
        ]], null, $this->admin->id);

        $this->assertSame(2, JournalEntry::where('source_type', 'GoodsReceiving')->count());

        $firstEntry = JournalEntry::where('source_type', 'GoodsReceiving')
            ->where('source_id', $first->id)->firstOrFail();
        $this->assertJournalLines($firstEntry, [
            ['1140', 20000, 0],
            ['2100', 0, 20000],
        ]);

        $secondEntry = JournalEntry::where('source_type', 'GoodsReceiving')
            ->where('source_id', $second->id)->firstOrFail();
        $this->assertJournalLines($secondEntry, [
            ['1140', 30000, 0],
            ['2100', 0, 30000],
        ]);
    }

    public function test_receiving_without_supplier_posts_no_journal(): void
    {
        $product = $this->createProduct();

        $supplierless = PurchaseOrder::create([
            'supplier_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'PO-NOSUP-'.Str::upper(Str::random(6)),
            'status' => 'ordered',
            'created_by' => $this->admin->id,
        ]);
        $supplierless->items()->create([
            'product_id' => $product->id,
            'qty_ordered' => 5,
            'qty_received' => 0,
            'unit_price' => 5000,
        ]);

        app(GoodsReceivingService::class)->receive($supplierless, [[
            'purchase_order_item_id' => $supplierless->items->first()->id,
            'qty_received' => 5,
        ]], null, $this->admin->id);

        $this->assertSame(0, JournalEntry::where('source_type', 'GoodsReceiving')->count());
    }

    public function test_repost_is_idempotent(): void
    {
        $product = $this->createProduct();
        $order = $this->createOrderedPo($product, 10);

        $receiving = app(GoodsReceivingService::class)->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'qty_received' => 10,
        ]], null, $this->admin->id);

        $first = JournalEntry::where('source_type', 'GoodsReceiving')->firstOrFail();
        $second = app(JournalService::class)->postGoodsReceivingJournal($receiving, null, 50000);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'GoodsReceiving')->count());
    }
}
