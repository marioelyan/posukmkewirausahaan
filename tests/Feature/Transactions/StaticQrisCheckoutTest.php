<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaticQrisCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_qris_checkout_is_paid_without_calling_a_gateway(): void
    {
        [$cashier] = $this->prepareCheckout();

        PaymentSetting::create([
            'default_gateway' => 'qris',
            'qris_enabled' => true,
            'qris_image_path' => 'payment/qris/merchant.png',
            'qris_merchant_name' => 'Toko Uji',
        ]);

        Http::fake();

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'discount' => 0,
                'grand_total' => 10000,
                'cash' => 10000,
                'change' => 0,
                'payment_gateway' => 'qris',
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame('qris', $transaction->payment_method);
        $this->assertSame('paid', $transaction->payment_status);
        $this->assertNull($transaction->qr_string);
        $this->assertNull($transaction->payment_reference);
        $this->assertNull($transaction->payment_url);

        Http::assertNothingSent();
    }

    public function test_static_qris_print_page_exposes_merchant_image(): void
    {
        [$cashier] = $this->prepareCheckout();

        PaymentSetting::create([
            'default_gateway' => 'cash',
            'qris_enabled' => true,
            'qris_image_path' => 'payment/qris/merchant.png',
        ]);

        $transaction = Transaction::create([
            'invoice' => 'INV-STATIC-QRIS',
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'payment_method' => 'qris',
            'payment_status' => 'paid',
            'grand_total' => 10000,
            'total' => 10000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        $this->actingAs($cashier)
            ->get(route('transactions.print', $transaction->invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Transactions/Print')
                ->where('transaction.invoice', 'INV-STATIC-QRIS')
                ->where('qrisImageUrl', fn ($url) => filled($url)));
    }

    public function test_split_payment_with_static_qris_marks_the_tender_paid(): void
    {
        [$cashier] = $this->prepareCheckout();

        PaymentSetting::create([
            'default_gateway' => 'cash',
            'qris_enabled' => true,
            'qris_image_path' => 'payment/qris/merchant.png',
        ]);

        Http::fake();

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'tenders' => [
                    ['method' => 'cash', 'amount' => 4000, 'cash_received' => 4000],
                    ['method' => 'qris', 'amount' => 6000],
                ],
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame('split', $transaction->payment_method);
        $this->assertSame('paid', $transaction->payment_status);
        $this->assertSame(
            ['paid', 'paid'],
            $transaction->tenders()->orderBy('id')->pluck('payment_status')->all()
        );

        Http::assertNothingSent();
    }

    public function test_payment_settings_update_uploads_static_qris_image(): void
    {
        Storage::fake('public');

        $admin = $this->createAdmin();

        PaymentSetting::create(['default_gateway' => 'cash']);

        $this->actingAs($admin)
            ->withSession($this->recentlyConfirmedSession())
            ->put(route('settings.payments.update'), [
                'default_gateway' => 'qris',
                'bank_transfer_enabled' => false,
                'qris_enabled' => true,
                'qris_merchant_name' => 'Toko Uji',
                'qris_image' => UploadedFile::fake()->image('qris.png'),
                'remove_qris_image' => false,
                'midtrans_enabled' => false,
                'midtrans_server_key' => '',
                'midtrans_client_key' => '',
                'midtrans_production' => false,
                'xendit_enabled' => false,
                'xendit_secret_key' => '',
                'xendit_public_key' => '',
                'xendit_callback_token' => '',
                'xendit_production' => false,
            ])
            ->assertRedirect(route('settings.payments.edit'))
            ->assertSessionHasNoErrors();

        $setting = PaymentSetting::firstOrFail();

        $this->assertTrue($setting->qris_enabled);
        $this->assertSame('Toko Uji', $setting->qris_merchant_name);
        $this->assertTrue($setting->isStaticQrisReady());
        $this->assertNotNull($setting->qris_image_path);
        Storage::disk('public')->assertExists($setting->qris_image_path);
    }

    public function test_enabling_static_qris_requires_an_image(): void
    {
        $admin = $this->createAdmin();

        PaymentSetting::create(['default_gateway' => 'cash']);

        $this->actingAs($admin)
            ->withSession($this->recentlyConfirmedSession())
            ->put(route('settings.payments.update'), [
                'default_gateway' => 'cash',
                'bank_transfer_enabled' => false,
                'qris_enabled' => true,
                'qris_merchant_name' => 'Toko Uji',
                'midtrans_enabled' => false,
                'midtrans_server_key' => '',
                'midtrans_client_key' => '',
                'midtrans_production' => false,
                'xendit_enabled' => false,
                'xendit_secret_key' => '',
                'xendit_public_key' => '',
                'xendit_callback_token' => '',
                'xendit_production' => false,
            ])
            ->assertSessionHasErrors('qris_image');

        $this->assertFalse((bool) PaymentSetting::firstOrFail()->qris_enabled);
    }

    public function test_removing_an_image_while_qris_is_enabled_keeps_the_stored_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('payment/qris/old.png', 'fake-image');

        $admin = $this->createAdmin();

        PaymentSetting::create([
            'default_gateway' => 'qris',
            'qris_enabled' => true,
            'qris_image_path' => 'payment/qris/old.png',
        ]);

        $this->actingAs($admin)
            ->withSession($this->recentlyConfirmedSession())
            ->put(route('settings.payments.update'), [
                'default_gateway' => 'qris',
                'bank_transfer_enabled' => false,
                'qris_enabled' => true,
                'remove_qris_image' => true,
                'midtrans_enabled' => false,
                'midtrans_server_key' => '',
                'midtrans_client_key' => '',
                'midtrans_production' => false,
                'xendit_enabled' => false,
                'xendit_secret_key' => '',
                'xendit_public_key' => '',
                'xendit_callback_token' => '',
                'xendit_production' => false,
            ])
            ->assertSessionHasErrors('qris_image');

        Storage::disk('public')->assertExists('payment/qris/old.png');
        $this->assertSame('payment/qris/old.png', PaymentSetting::firstOrFail()->qris_image_path);
    }

    public function test_enabled_gateways_includes_static_qris_only_when_ready(): void
    {
        $ready = PaymentSetting::create([
            'default_gateway' => 'cash',
            'qris_enabled' => true,
            'qris_image_path' => 'payment/qris/merchant.png',
        ]);

        $this->assertContains(
            PaymentSetting::GATEWAY_QRIS,
            collect($ready->enabledGateways())->pluck('value')->all()
        );

        $notReady = PaymentSetting::create([
            'default_gateway' => 'cash',
            'qris_enabled' => false,
        ]);

        $this->assertNotContains(
            PaymentSetting::GATEWAY_QRIS,
            collect($notReady->enabledGateways())->pluck('value')->all()
        );
    }

    private function createAdmin(): User
    {
        foreach (['payment-settings-access', 'payment-settings-update'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = User::factory()->create();
        $admin->markEmailAsVerified();
        $admin->givePermissionTo([
            'payment-settings-access',
            'payment-settings-update',
        ]);

        return $admin;
    }

    private function prepareCheckout(): array
    {
        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $cashier = User::factory()->create();
        $cashier->markEmailAsVerified();
        $cashier->givePermissionTo('transactions-access');

        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(CashierShiftService::class)->openShift($cashier, $cashier, 0, null, $warehouse->id);

        $category = Category::create([
            'name' => 'Test',
            'image' => '',
            'description' => '',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => '',
            'barcode' => 'STATIC-QRIS-'.Str::random(8),
            'sku' => 'STATIC-QRIS-'.Str::random(8),
            'title' => 'Produk QRIS Statis',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 10,
            'tax_rate' => 0,
        ]);

        $warehouse->products()->attach($product->id, ['stock' => 10]);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
        ]);

        return [$cashier, $product, $warehouse];
    }
}
