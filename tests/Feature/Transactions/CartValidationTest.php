<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CartValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->actingAs($this->cashier);

        $this->category = Category::create([
            'name' => 'Sembako',
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
    }

    private function makeProduct(int $stock = 25): Product
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => $stock,
            'tax_rate' => 0,
        ]);

        $this->warehouse->products()->attach($product->id, ['stock' => $stock]);

        return $product;
    }

    private function openShiftForCashier(): void
    {
        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            100000,
            null,
            $this->warehouse->id
        );
    }

    public function test_add_to_cart_rejects_zero_qty(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct();

        $this->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'qty' => 0,
            ])
            ->assertSessionHasErrors('qty');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_add_to_cart_rejects_negative_qty(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct();

        $this->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'qty' => -2,
            ])
            ->assertSessionHasErrors('qty');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_add_to_cart_rejects_unit_not_belonging_to_product(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct();

        // BOX exists from migration seed but product only has default PCS base unit
        $box = Unit::where('code', 'BOX')->firstOrFail();
        $pcs = Unit::where('code', 'PCS')->firstOrFail();
        $product->units()->attach($pcs->id, [
            'is_base' => true,
            'conversion_factor' => 1,
            'buy_price' => 45000,
            'sell_price' => 60000,
        ]);

        $this->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_id' => $box->id,
            ])
            ->assertSessionHas('error', 'Satuan tidak valid untuk produk ini.');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_update_cart_checks_base_stock_for_box_unit(): void
    {
        $this->openShiftForCashier();
        // Only 10 base units (PCS) available = less than 1 BOX (factor 12)
        $product = $this->makeProduct(10);

        $pcs = Unit::where('code', 'PCS')->firstOrFail();
        $box = Unit::where('code', 'BOX')->firstOrFail();
        $product->units()->sync([
            $pcs->id => ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 45000, 'sell_price' => 60000],
            $box->id => ['is_base' => false, 'conversion_factor' => 12, 'buy_price' => 540000, 'sell_price' => 660000],
        ]);

        $cart = Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'unit_id' => $box->id,
            'conversion_factor' => 12,
            'qty' => 1,
            'price' => 660000,
        ]);

        $this->patch(route('transactions.updateCart', $cart->id), ['qty' => 1])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Stok tidak mencukupi. Tersedia: 10']);
    }

    public function test_update_cart_uses_unit_sell_price(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct(100);

        $pcs = Unit::where('code', 'PCS')->firstOrFail();
        $box = Unit::where('code', 'BOX')->firstOrFail();
        $product->units()->sync([
            $pcs->id => ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 45000, 'sell_price' => 60000],
            $box->id => ['is_base' => false, 'conversion_factor' => 12, 'buy_price' => 540000, 'sell_price' => 660000],
        ]);

        $cart = Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'unit_id' => $box->id,
            'conversion_factor' => 12,
            'qty' => 1,
            'price' => 660000,
        ]);

        $this->from(route('transactions.index'))
            ->patch(route('transactions.updateCart', $cart->id), ['qty' => 2]);

        $cart = $cart->fresh();
        $this->assertSame(2, (int) $cart->qty);
        $this->assertSame(1320000, (int) $cart->price);
    }

    public function test_add_to_cart_is_cumulative_and_cannot_exceed_stock(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct(6);

        for ($i = 0; $i < 6; $i++) {
            $this->from(route('transactions.index'))
                ->post(route('transactions.addToCart'), [
                    'product_id' => $product->id,
                    'qty' => 1,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(6, (int) Cart::where('product_id', $product->id)->first()->qty);

        // The 7th add must be rejected (regression: stock 6 but 15 got in).
        $this->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertSessionHas('error', 'Stok tidak mencukupi. Tersedia: 6');

        $this->assertSame(6, (int) Cart::where('product_id', $product->id)->first()->fresh()->qty);
    }

    public function test_add_to_cart_rejects_qty_above_stock_in_single_call(): void
    {
        $this->openShiftForCashier();
        $product = $this->makeProduct(6);

        $this->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'qty' => 15,
            ])
            ->assertSessionHas('error', 'Stok tidak mencukupi. Tersedia: 6');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_index_lists_warehouse_products_including_zero_stock_with_available_stock(): void
    {
        $this->openShiftForCashier();
        $inStock = $this->makeProduct(6);
        $outOfStock = $this->makeProduct(0);

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($inStock, $outOfStock) {
                $products = collect($page->toArray()['props']['products'] ?? []);

                $serializedInStock = $products->firstWhere('id', $inStock->id);
                $serializedOutOfStock = $products->firstWhere('id', $outOfStock->id);

                $this->assertNotNull($serializedInStock);
                $this->assertNotNull($serializedOutOfStock);
                $this->assertSame(6.0, (float) $serializedInStock['available_stock']);
                $this->assertSame(0.0, (float) $serializedOutOfStock['available_stock']);
            });
    }
}
