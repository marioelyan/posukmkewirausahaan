<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MasterDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('multi_business.enabled', true);

        Route::middleware(['web', 'business', 'business.required'])->group(function (): void {
            Route::get('/_test/products', fn () => response()->json([
                'ids' => Product::query()->orderBy('id')->pluck('id')->all(),
            ]));

            Route::get('/_test/products/{id}', fn (int $id) => response()->json([
                'id' => Product::query()->findOrFail($id)->id,
            ]));

            Route::delete('/_test/products/{id}', function (int $id) {
                Product::query()->findOrFail($id)->delete();

                return response()->json(['deleted' => true]);
            });

            Route::post('/_test/products', function (Request $request) {
                $product = Product::create([
                    'image' => 'x.png',
                    'barcode' => $request->input('barcode', 'BC-'.uniqid()),
                    'title' => 'Produk',
                    'description' => 'Testing',
                    'buy_price' => 1000,
                    'sell_price' => 2000,
                    'stock' => 0,
                    'category_id' => $request->input('category_id'),
                    'business_id' => $request->input('business_id'),
                ]);

                return response()->json([
                    'id' => $product->id,
                    'business_id' => $product->business_id,
                ]);
            });
        });
    }

    private function member(User $user, Business $business, string $status = BusinessUser::STATUS_ACTIVE): void
    {
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessUser::ROLE_OWNER,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    private function category(Business $business, string $name): Category
    {
        return Category::create([
            'name' => $name,
            'description' => $name,
            'business_id' => $business->id,
        ]);
    }

    private function product(Business $business, string $barcode): Product
    {
        return Product::create([
            'image' => 'x.png',
            'barcode' => $barcode,
            'title' => 'Produk '.$barcode,
            'description' => 'Testing',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 0,
            'category_id' => $this->category($business, 'Kat '.$barcode)->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_index_only_returns_active_business_products(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $productA = $this->product($businessA, 'BC-A');
        $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get('/_test/products')
            ->assertOk()
            ->assertJson(['ids' => [$productA->id]]);
    }

    public function test_detail_of_another_business_is_not_found(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $productB = $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get("/_test/products/{$productB->id}")
            ->assertNotFound();
    }

    public function test_delete_of_another_business_is_not_found_and_writes_nothing(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $productB = $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->delete("/_test/products/{$productB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $productB->id]);
    }

    public function test_spoofed_business_id_in_payload_is_overridden_by_context(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $categoryA = $this->category($businessA, 'Kat A');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->post('/_test/products', [
                'barcode' => 'BC-SPOOF',
                'category_id' => $categoryA->id,
                'business_id' => $businessB->id,
            ])
            ->assertOk()
            ->assertJson(['business_id' => $businessA->id]);

        $this->assertDatabaseHas('products', [
            'barcode' => 'BC-SPOOF',
            'business_id' => $businessA->id,
        ]);
    }

    public function test_query_parameter_business_id_is_ignored(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $productA = $this->product($businessA, 'BC-A');
        $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get('/_test/products?business_id='.$businessB->id)
            ->assertOk()
            ->assertJson(['ids' => [$productA->id]]);
    }

    public function test_user_without_membership_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/products')
            ->assertForbidden();
    }

    public function test_suspended_membership_is_forbidden(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $business, BusinessUser::STATUS_SUSPENDED);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/products')
            ->assertForbidden();
    }

    public function test_multiple_memberships_require_explicit_active_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);
        $this->member($user, $businessB);

        $productA = $this->product($businessA, 'BC-A');
        $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->get('/_test/products')
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get('/_test/products')
            ->assertOk()
            ->assertJson(['ids' => [$productA->id]]);
    }

    public function test_disabling_feature_flag_restores_legacy_global_visibility(): void
    {
        config()->set('multi_business.enabled', false);

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create();
        $this->member($user, $businessA);

        $productA = $this->product($businessA, 'BC-A');
        $productB = $this->product($businessB, 'BC-B');

        $this->actingAs($user)
            ->withSession(['active_business_id' => $businessA->id])
            ->get('/_test/products')
            ->assertOk()
            ->assertJsonCount(2, 'ids')
            ->assertJson(['ids' => [$productA->id, $productB->id]]);
    }
}
