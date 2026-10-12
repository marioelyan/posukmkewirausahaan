<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\CustomerVoucher;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeOutlet(string $code): Outlet
    {
        return Outlet::create(['code' => $code, 'name' => $code]);
    }

    private function makeProduct(string $barcode): Product
    {
        $category = Category::create(['name' => 'Umum', 'description' => 'Umum']);

        return Product::create([
            'image' => 'x.png',
            'barcode' => $barcode,
            'title' => 'Produk '.$barcode,
            'description' => 'Testing',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 0,
            'category_id' => $category->id,
        ]);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $outlet = $this->makeOutlet('MAL');
        $product = $this->makeProduct('BC-DRY');

        $this->artisan('business:backfill --create-default')->assertSuccessful();

        $this->assertSame(0, Business::count());
        $this->assertNull($outlet->fresh()->business_id);
        $this->assertNull($product->fresh()->business_id);
    }

    public function test_apply_creates_default_business_and_maps_rows(): void
    {
        $outlet = $this->makeOutlet('MAL');
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id,
            'code' => 'WH-MAL',
            'name' => 'Gudang MAL',
            'type' => 'branch',
        ]);
        $product = $this->makeProduct('BC-APPLY');
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => 6281, 'address' => 'Jl']);

        $this->artisan('business:backfill --create-default --name="Toko Saya" --apply --confirm-legacy-ownership')
            ->assertSuccessful();

        $business = Business::first();
        $this->assertNotNull($business);
        $this->assertSame('Toko Saya', $business->name);
        $this->assertSame($business->id, $outlet->fresh()->business_id);
        $this->assertSame($business->id, $warehouse->fresh()->business_id);
        $this->assertSame($business->id, $product->fresh()->business_id);
        $this->assertSame($business->id, $customer->fresh()->business_id);
    }

    public function test_apply_is_idempotent(): void
    {
        $outlet = $this->makeOutlet('MAL');
        $this->makeProduct('BC-IDEM');

        $this->artisan('business:backfill --create-default --apply --confirm-legacy-ownership')->assertSuccessful();
        $this->artisan('business:backfill --apply --confirm-legacy-ownership')->assertSuccessful();

        $this->assertSame(1, Business::count());
        $this->assertSame(Business::first()->id, $outlet->fresh()->business_id);
    }

    public function test_apply_without_confirmation_aborts_without_writing(): void
    {
        $outlet = $this->makeOutlet('MAL');
        $product = $this->makeProduct('BC-NOCONFIRM');

        $this->artisan('business:backfill --create-default --apply')->assertFailed();

        $this->assertSame(0, Business::count());
        $this->assertNull($outlet->fresh()->business_id);
        $this->assertNull($product->fresh()->business_id);
    }

    public function test_multiple_businesses_without_target_aborts_without_writing(): void
    {
        Business::factory()->create();
        Business::factory()->create();
        $outlet = $this->makeOutlet('MAL');

        $this->artisan('business:backfill --apply')->assertFailed();

        $this->assertNull($outlet->fresh()->business_id);
    }

    public function test_conflict_with_another_business_aborts_without_writing(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar', 'business_id' => $businessB->id]);

        $this->artisan("business:backfill --business={$businessA->id} --apply")->assertFailed();

        $this->assertSame($businessB->id, $outlet->fresh()->business_id);
    }

    public function test_without_business_and_without_create_default_is_a_noop(): void
    {
        $outlet = $this->makeOutlet('MAL');

        $this->artisan('business:backfill --apply')->assertSuccessful();

        $this->assertSame(0, Business::count());
        $this->assertNull($outlet->fresh()->business_id);
    }

    public function test_apply_also_maps_crm_blocks(): void
    {
        $segment = CustomerSegment::create([
            'name' => 'VIP',
            'slug' => 'vip',
            'type' => CustomerSegment::TYPE_MANUAL,
        ]);
        $voucher = CustomerVoucher::create([
            'customer_id' => Customer::create(['name' => 'Budi', 'no_telp' => 6281, 'address' => 'Jl'])->id,
            'code' => 'VCR-1',
            'name' => 'Diskon',
            'discount_type' => CustomerVoucher::TYPE_FIXED_AMOUNT,
            'discount_value' => 5000,
            'minimum_order' => 0,
        ]);

        $this->artisan('business:backfill --create-default --apply --confirm-legacy-ownership')->assertSuccessful();

        $business = Business::first();
        $this->assertNotNull($business);
        $this->assertSame($business->id, $segment->fresh()->business_id);
        $this->assertSame($business->id, $voucher->fresh()->business_id);
    }
}
