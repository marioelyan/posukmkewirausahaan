<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WalkInCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access'] as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_checkout_without_customer_succeeds(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $product = $this->createProduct();

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        $response = $this
            ->from(route('transactions.index'))
            ->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => null,
                'discount' => 0,
                'grand_total' => $product->sell_price,
                'cash' => $product->sell_price,
                'change' => 0,
            ]);

        $response->assertRedirect();

        $transaction = Transaction::latest('id')->first();

        $this->assertNotNull($transaction);
        $this->assertNull($transaction->customer_id);
    }

    public function test_pay_later_without_customer_is_rejected(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $product = $this->createProduct();

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        $response = $this
            ->from(route('transactions.index'))
            ->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => null,
                'pay_later' => '1',
                'due_date' => now()->addDays(7)->toDateString(),
                'grand_total' => $product->sell_price,
            ]);

        $response->assertRedirect(route('transactions.index'));
        $response->assertSessionHas('error', 'Nota barang memerlukan pelanggan.');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_api_pay_later_without_customer_fails_validation(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $product = $this->createProduct();

        $response = $this
            ->actingAs($cashier)
            ->postJson('/api/v1/pos/checkout', [
                'items_summary' => 'ignored',
                'payment_method' => 'pay_later',
                'due_date' => now()->addDays(7)->toDateString(),
                'grand_total' => $product->sell_price,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer_id']);
        $this->assertDatabaseCount('transactions', 0);
    }

    private function createCashier(): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo(['transactions-access', 'cashier-shifts-access']);

        return $user;
    }

    private function openShiftFor(User $cashier): CashierShift
    {
        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    private function createProduct(): Product
    {
        $category = Category::create([
            'name' => 'Sembako',
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);

        $warehouse = Warehouse::where('type', 'main')->first() ?? Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 25]);

        return $product;
    }
}
