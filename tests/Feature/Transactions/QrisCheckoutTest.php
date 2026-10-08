<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QrisCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_web_checkout_with_qris_stores_qr_string(): void
    {
        $cashier = $this->createCashier();
        $this->openShiftFor($cashier);
        $customer = Customer::create([
            'name' => 'QRIS Customer',
            'no_telp' => 62812300,
            'address' => 'Jl. QRIS No. 1',
        ]);
        $product = $this->createProduct();

        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'TRX-QRIS',
                'redirect_url' => 'https://pay.midtrans.test/qris',
                'token' => 'snap-token',
                'qr_string' => '00020101021226610014ID.CO.QRIS.WWW',
            ], 200),
        ]);

        $cart = Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'discount' => 0,
                'grand_total' => $cart->price,
                'cash' => 0,
                'change' => 0,
                'payment_gateway' => 'qris',
            ]);

        $transaction = Transaction::latest('id')->first();

        $response->assertRedirect(route('transactions.print', $transaction->invoice));
        $this->assertSame('qris', $transaction->payment_method);
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertSame('00020101021226610014ID.CO.QRIS.WWW', $transaction->qr_string);
        $this->assertSame('TRX-QRIS', $transaction->payment_reference);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'midtrans.com')
            && ($request['enabled_payments'] ?? []) === ['qris', 'gopay', 'shopeepay']);
    }

    public function test_status_endpoint_returns_payment_status(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-TEST',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'qris',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        $this->actingAs($cashier)
            ->getJson(route('transactions.status', $transaction->invoice))
            ->assertOk()
            ->assertJson(['payment_status' => 'pending']);

        $transaction->update(['payment_status' => 'paid']);

        $this->actingAs($cashier)
            ->getJson(route('transactions.status', $transaction->invoice))
            ->assertOk()
            ->assertJson(['payment_status' => 'paid']);
    }

    public function test_qris_image_endpoint_renders_png(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-PNG',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'qris',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
            'qr_string' => '00020101021226610014ID.CO.QRIS.WWW',
        ]);

        $response = $this->actingAs($cashier)
            ->get(route('transactions.qr', $transaction->invoice));

        $response->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
    }

    public function test_qris_image_endpoint_404_without_qr_string(): void
    {
        $cashier = $this->createCashier();
        $transaction = Transaction::create([
            'invoice' => 'INV-QRIS-NOQR',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'grand_total' => 60000,
            'total' => 60000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        $this->actingAs($cashier)
            ->get(route('transactions.qr', $transaction->invoice))
            ->assertNotFound();
    }

    private function createCashier(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);

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
            'name' => 'Sembako QRIS',
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'title' => 'Produk QRIS',
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);
    }
}
