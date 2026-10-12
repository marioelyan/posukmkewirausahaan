<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves the verified business context is available before implicit route-model
 * binding (SubstituteBindings) resolves tenant records, so a user in business A
 * cannot open a record of business B by changing the URL id.
 */
class TenantRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('multi_business.enabled', true);

        Route::middleware(['web', 'business', 'business.required'])->group(function (): void {
            Route::get('/_test/tenant/products/{product}', fn (Product $product) => response()->json([
                'id' => $product->id,
                'business_id' => $product->business_id,
            ]));
        });

        // Resolve context but do NOT require it: binding must still fail closed.
        Route::middleware(['web', 'business'])->group(function (): void {
            Route::get('/_test/tenant-open/products/{product}', fn (Product $product) => response()->json([
                'id' => $product->id,
            ]));
        });
    }

    private function member(User $user, Business $business): void
    {
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessUser::ROLE_OWNER,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    private function product(Business $business, string $barcode): Product
    {
        $category = Category::create(['name' => 'Kat '.$barcode, 'description' => '-', 'business_id' => $business->id]);

        return Product::create([
            'image' => 'x.png',
            'barcode' => $barcode,
            'title' => 'Produk '.$barcode,
            'description' => '-',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 0,
            'category_id' => $category->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_implicit_binding_resolves_own_business_record(): void
    {
        $businessA = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);
        $productA = $this->product($businessA, 'BC-A');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get("/_test/tenant/products/{$productA->id}")
            ->assertOk()
            ->assertJson(['id' => $productA->id, 'business_id' => $businessA->id]);
    }

    public function test_implicit_binding_rejects_other_business_record(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);
        $productB = $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get("/_test/tenant/products/{$productB->id}")
            ->assertNotFound();
    }

    public function test_binding_fails_closed_without_required_context(): void
    {
        config()->set('multi_business.enabled', false);

        $businessA = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);
        $productA = $this->product($businessA, 'BC-A');

        config()->set('multi_business.enabled', true);

        // No session active_business_id and a sole membership would auto-resolve,
        // so use a user with two businesses to guarantee no context.
        $businessB = Business::factory()->create();
        $this->member($user, $businessB);

        $this->actingAs($user)
            ->get("/_test/tenant-open/products/{$productA->id}")
            ->assertNotFound();
    }
}
