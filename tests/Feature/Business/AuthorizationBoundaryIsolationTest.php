<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\GoodsReceiving;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PriceList;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\CashierShiftService;
use App\Support\Tenancy\TenantOwnership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Build 4E — authorization & tenant boundary audit.
 *
 * Complements the 4A–4D isolation suites by proving the boundaries that were
 * not yet covered: pricing-rule / price-list foreign-key injection, unowned
 * outlet-less promotion rules, supplier-return foreign keys (including the
 * payable mutation path), offline-sync idempotency leakage, and active-shift
 * context consistency. Controls are proven through real operation outcomes
 * (HTTP status, validation errors, persisted rows), never by config presence.
 */
class AuthorizationBoundaryIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $admin;

    private Outlet $outletA;

    private Outlet $outletB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Category $categoryA;

    private Category $categoryB;

    private Product $productA;

    private Product $productB;

    private int $supplierBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        config()->set('multi_business.enabled', true);

        $context = app(BusinessContext::class);
        $context->clear();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OA',
            'name' => 'Outlet A',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->outletB = Outlet::create([
            'business_id' => $this->businessB->id,
            'code' => 'OB',
            'name' => 'Outlet B',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->warehouseA = Warehouse::create([
            'business_id' => $this->businessA->id,
            'outlet_id' => $this->outletA->id,
            'code' => 'WA',
            'name' => 'Warehouse A',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->warehouseB = Warehouse::create([
            'business_id' => $this->businessB->id,
            'outlet_id' => $this->outletB->id,
            'code' => 'WB',
            'name' => 'Warehouse B',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->categoryA = Category::create([
            'business_id' => $this->businessA->id,
            'name' => 'Category A',
            'description' => '-',
        ]);

        $this->categoryB = Category::create([
            'business_id' => $this->businessB->id,
            'name' => 'Category B',
            'description' => '-',
        ]);

        $this->productA = Product::create([
            'business_id' => $this->businessA->id,
            'image' => 'a.png',
            'barcode' => 'PRD-A',
            'sku' => 'SKU-A',
            'title' => 'Product A',
            'description' => '-',
            'category_id' => $this->categoryA->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 10,
            'tax_rate' => 0,
        ]);

        $this->productB = Product::create([
            'business_id' => $this->businessB->id,
            'image' => 'b.png',
            'barcode' => 'PRD-B',
            'sku' => 'SKU-B',
            'title' => 'Product B',
            'description' => '-',
            'category_id' => $this->categoryB->id,
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 5,
            'tax_rate' => 0,
        ]);

        $this->supplierBId = Supplier::create([
            'business_id' => $this->businessB->id,
            'name' => 'Supplier B',
            'phone' => '0800',
            'email' => 'supplier-b@example.test',
            'address' => '-',
        ])->id;

        $context->clear();

        Route::middleware(['web', 'business', 'business.required'])
            ->get('/_test/active-business', fn () => response()->json([
                'business_id' => app(BusinessContext::class)->businessId(),
            ]));
    }

    private function asBusinessA()
    {
        return $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id]);
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

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Promo '.Str::random(6),
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'is_active' => true,
            'priority' => 1,
            'target_type' => PricingRule::TARGET_PRODUCT,
            'product_id' => $this->productA->id,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 10,
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // Pricing rules — cross-business foreign keys and unowned (outlet-less) rules
    // ---------------------------------------------------------------------

    public function test_pricing_rule_store_rejects_foreign_product_target(): void
    {
        $this->asBusinessA()
            ->post(route('pricing-rules.store'), $this->rulePayload(['product_id' => $this->productB->id]))
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, PricingRule::count());
    }

    public function test_pricing_rule_store_rejects_foreign_category_target(): void
    {
        $this->asBusinessA()
            ->post(route('pricing-rules.store'), $this->rulePayload([
                'target_type' => PricingRule::TARGET_CATEGORY,
                'product_id' => null,
                'category_id' => $this->categoryB->id,
            ]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, PricingRule::count());
    }

    public function test_pricing_rule_bundle_rejects_foreign_product(): void
    {
        $this->asBusinessA()
            ->post(route('pricing-rules.store'), $this->rulePayload([
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'target_type' => PricingRule::TARGET_ALL,
                'product_id' => null,
                'discount_type' => PricingRule::TYPE_FIXED_PRICE,
                'discount_value' => 3000,
                'bundle_items' => [
                    ['product_id' => $this->productB->id, 'quantity' => 1],
                    ['product_id' => $this->productA->id, 'quantity' => 1],
                ],
            ]))
            ->assertSessionHasErrors('bundle_items.0.product_id');

        $this->assertSame(0, PricingRule::count());
    }

    public function test_pricing_rule_store_accepts_own_product(): void
    {
        $payload = $this->rulePayload();

        $this->asBusinessA()
            ->post(route('pricing-rules.store'), $payload)
            ->assertRedirect(route('pricing-rules.index'));

        $this->assertDatabaseHas('pricing_rules', [
            'name' => $payload['name'],
            'product_id' => $this->productA->id,
            'outlet_id' => $this->outletA->id,
        ]);
    }

    public function test_outlet_less_pricing_rule_is_not_reachable_under_tenancy(): void
    {
        $rule = PricingRule::create([
            'name' => 'Legacy Global Rule',
            'outlet_id' => null,
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'target_type' => PricingRule::TARGET_ALL,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 5,
            'priority' => 0,
        ]);

        $this->asBusinessA()
            ->get(route('pricing-rules.edit', $rule))
            ->assertNotFound();
    }

    public function test_pricing_rule_of_another_outlet_is_not_reachable(): void
    {
        $rule = PricingRule::create([
            'name' => 'Outlet B Rule',
            'outlet_id' => $this->outletB->id,
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'target_type' => PricingRule::TARGET_ALL,
            'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE,
            'discount_value' => 5,
            'priority' => 0,
        ]);

        $this->asBusinessA()
            ->get(route('pricing-rules.edit', $rule))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Price list items — cross-business product foreign key
    // ---------------------------------------------------------------------

    private function priceList(): PriceList
    {
        return PriceList::create([
            'business_id' => $this->businessA->id,
            'outlet_id' => $this->outletA->id,
            'name' => 'Price List A',
            'slug' => 'price-list-a',
            'customer_scope' => 'all',
            'is_active' => true,
            'priority' => 0,
        ]);
    }

    public function test_price_list_item_rejects_foreign_product(): void
    {
        $priceList = $this->priceList();

        $this->actingAs($this->admin)
            ->withSession([
                'active_business_id' => $this->businessA->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->post(route('price-lists.items.update', $priceList), [
                'product_id' => $this->productB->id,
                'price' => 1500,
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseMissing('price_list_items', [
            'price_list_id' => $priceList->id,
            'product_id' => $this->productB->id,
        ]);
    }

    public function test_price_list_item_accepts_own_product(): void
    {
        $priceList = $this->priceList();

        $this->actingAs($this->admin)
            ->withSession([
                'active_business_id' => $this->businessA->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->post(route('price-lists.items.update', $priceList), [
                'product_id' => $this->productA->id,
                'price' => 1500,
            ]);

        $this->assertDatabaseHas('price_list_items', [
            'price_list_id' => $priceList->id,
            'product_id' => $this->productA->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Supplier returns — cross-business foreign keys and payable mutation
    // ---------------------------------------------------------------------

    private function supplierReturnPayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $this->productA->id, 'qty_returned' => 1, 'unit_price' => 1000],
            ],
        ], $overrides);
    }

    public function test_supplier_return_rejects_foreign_supplier(): void
    {
        $this->asBusinessA()
            ->post(route('supplier-returns.store'), $this->supplierReturnPayload([
                'supplier_id' => $this->supplierBId,
            ]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_supplier_return_rejects_foreign_product(): void
    {
        $this->asBusinessA()
            ->post(route('supplier-returns.store'), $this->supplierReturnPayload([
                'items' => [['product_id' => $this->productB->id, 'qty_returned' => 1]],
            ]))
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_supplier_return_rejects_foreign_goods_receiving(): void
    {
        $poB = PurchaseOrder::create([
            'document_number' => 'PO-B-GR',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $receiving = GoodsReceiving::create([
            'purchase_order_id' => $poB->id,
            'warehouse_id' => $this->warehouseB->id,
            'document_number' => 'GR-B',
            'received_at' => now(),
        ]);

        $this->asBusinessA()
            ->post(route('supplier-returns.store'), $this->supplierReturnPayload([
                'goods_receiving_id' => $receiving->id,
            ]))
            ->assertSessionHasErrors('goods_receiving_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_supplier_return_rejects_payable_of_another_business(): void
    {
        $poB = PurchaseOrder::create([
            'document_number' => 'PO-B',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $payableB = Payable::create([
            'document_number' => 'PAY-B',
            'total' => 5000,
            'purchase_order_id' => $poB->id,
        ]);

        $this->asBusinessA()
            ->post(route('supplier-returns.store'), $this->supplierReturnPayload([
                'payable_id' => $payableB->id,
            ]))
            ->assertSessionHasErrors('payable_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_supplier_return_accepts_payable_owned_via_own_purchase_order(): void
    {
        $poA = PurchaseOrder::create([
            'document_number' => 'PO-A',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseA->id,
        ]);

        $payableA = Payable::create([
            'document_number' => 'PAY-A',
            'total' => 5000,
            'purchase_order_id' => $poA->id,
        ]);

        $this->asBusinessA()
            ->post(route('supplier-returns.store'), $this->supplierReturnPayload([
                'payable_id' => $payableA->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('supplier_returns', [
            'payable_id' => $payableA->id,
        ]);
    }

    public function test_tenant_ownership_resolves_payables_via_purchase_order(): void
    {
        $poA = PurchaseOrder::create([
            'document_number' => 'PO-A',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseA->id,
        ]);
        $poB = PurchaseOrder::create([
            'document_number' => 'PO-B',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $payableA = Payable::create(['document_number' => 'PAY-A', 'total' => 1000, 'purchase_order_id' => $poA->id]);
        $payableB = Payable::create(['document_number' => 'PAY-B', 'total' => 1000, 'purchase_order_id' => $poB->id]);
        $manual = Payable::create(['document_number' => 'PAY-MANUAL', 'total' => 1000]);

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('payables', $payableA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('payables', $payableB->id));
        // Manual payables carry no purchase order, so ownership cannot be proven.
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('payables', $manual->id));
    }

    public function test_tenant_ownership_resolves_receivables_via_transaction(): void
    {
        $cashier = User::factory()->create();

        $trxA = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'INV-A',
            'grand_total' => 1000,
            'cash' => 1000,
            'change' => 0,
            'discount' => 0,
            'warehouse_id' => $this->warehouseA->id,
        ]);
        $trxB = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'INV-B',
            'grand_total' => 1000,
            'cash' => 1000,
            'change' => 0,
            'discount' => 0,
            'warehouse_id' => $this->warehouseB->id,
        ]);

        $receivableA = Receivable::create(['invoice' => 'RCV-A', 'total' => 1000, 'transaction_id' => $trxA->id]);
        $receivableB = Receivable::create(['invoice' => 'RCV-B', 'total' => 1000, 'transaction_id' => $trxB->id]);
        $manual = Receivable::create(['invoice' => 'RCV-MANUAL', 'total' => 1000]);

        $ownership = app(TenantOwnership::class);
        app(BusinessContext::class)->setActive($this->businessA);

        $this->assertTrue($ownership->entityBelongsToActiveBusiness('receivables', $receivableA->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('receivables', $receivableB->id));
        $this->assertFalse($ownership->entityBelongsToActiveBusiness('receivables', $manual->id));
    }

    // ---------------------------------------------------------------------
    // Offline sync — idempotency lookup must not leak another business
    // ---------------------------------------------------------------------

    public function test_offline_sync_idempotency_lookup_is_business_scoped(): void
    {
        $uuid = (string) Str::uuid();
        $cashier = User::factory()->create();

        Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'INV-B-SYNC',
            'grand_total' => 2000,
            'cash' => 2000,
            'change' => 0,
            'discount' => 0,
            'warehouse_id' => $this->warehouseB->id,
            'client_uuid' => $uuid,
            'sync_fingerprint' => 'fingerprint-b',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->postJson('/api/v1/pos/transactions/sync', [
                'transactions' => [[
                    'client_uuid' => $uuid,
                    'items' => [['product_id' => $this->productA->id, 'qty' => 1]],
                ]],
            ])
            ->assertOk();

        $result = $response->json('data.results.0');

        // Business B's transaction must never be echoed back as a duplicate.
        $this->assertNotSame('duplicate', $result['status']);
        $this->assertArrayNotHasKey('invoice', $result);
        $this->assertArrayNotHasKey('transaction_id', $result);
    }

    // ---------------------------------------------------------------------
    // Active cashier shift must belong to the active business
    // ---------------------------------------------------------------------

    public function test_active_shift_is_constrained_to_active_business(): void
    {
        $cashier = User::factory()->create();

        $shiftA = CashierShift::create([
            'user_id' => $cashier->id,
            'business_id' => $this->businessA->id,
            'opened_by' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
            'warehouse_id' => $this->warehouseA->id,
            'status' => CashierShift::STATUS_OPEN,
        ]);

        $shiftB = CashierShift::create([
            'user_id' => $cashier->id,
            'business_id' => $this->businessB->id,
            'opened_by' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
            'warehouse_id' => $this->warehouseB->id,
            'status' => CashierShift::STATUS_OPEN,
        ]);

        $service = app(CashierShiftService::class);

        app(BusinessContext::class)->setActive($this->businessA);
        $this->assertSame($shiftA->id, $service->getActiveShiftForUser($cashier->id)?->id);

        app(BusinessContext::class)->setActive($this->businessB);
        $this->assertSame($shiftB->id, $service->getActiveShiftForUser($cashier->id)?->id);
    }

    public function test_active_shift_is_not_resolved_without_context_when_tenancy_enabled(): void
    {
        $cashier = User::factory()->create();

        CashierShift::create([
            'user_id' => $cashier->id,
            'business_id' => $this->businessA->id,
            'opened_by' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
            'warehouse_id' => $this->warehouseA->id,
            'status' => CashierShift::STATUS_OPEN,
        ]);

        app(BusinessContext::class)->clear();

        $this->assertNull(app(CashierShiftService::class)->getActiveShiftForUser($cashier->id));
    }

    // ---------------------------------------------------------------------
    // Auth / token / context resolution boundaries
    // ---------------------------------------------------------------------

    public function test_api_requires_a_token(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
    }

    public function test_api_rejects_insufficient_token_ability(): void
    {
        $user = User::factory()->create();
        $this->member($user, $this->businessA);

        Sanctum::actingAs($user, ['user:read']);

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/pos/shift')
            ->assertForbidden();
    }

    public function test_non_super_member_resolves_own_business_via_header(): void
    {
        $user = User::factory()->create();
        $this->member($user, $this->businessA);

        Sanctum::actingAs($user, ['products-access']);

        $this->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products')
            ->assertOk();
    }

    public function test_non_super_member_cannot_resolve_other_business_via_header(): void
    {
        $user = User::factory()->create();
        $this->member($user, $this->businessA);

        Sanctum::actingAs($user, ['products-access']);

        $this->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_suspended_business_cannot_be_selected(): void
    {
        $suspended = Business::factory()->create(['status' => Business::STATUS_SUSPENDED]);
        $user = User::factory()->create();
        $this->member($user, $suspended);

        Sanctum::actingAs($user, ['products-access']);

        $this->withHeader('X-Business-Id', (string) $suspended->id)
            ->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_session_business_wins_over_request_header(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->getJson('/_test/active-business')
            ->assertOk()
            ->assertJson(['business_id' => $this->businessA->id]);
    }

    public function test_context_is_reset_between_requests(): void
    {
        // A non-super member with two memberships so there is no sole
        // membership to auto-resolve: without an explicit selection the
        // request must fail closed (super-admins intentionally fall back to
        // the first active business, so they cannot exercise this path).
        $member = User::where('email', 'manager@gmail.com')->firstOrFail();
        $member->markEmailAsVerified();
        $this->member($member, $this->businessA);
        $this->member($member, $this->businessB);

        // Request 1: explicit session context A.
        $this->actingAs($member)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->getJson('/_test/active-business')
            ->assertOk()
            ->assertJson(['business_id' => $this->businessA->id]);

        // Request 2: session cleared -> fail closed, nothing leaked over.
        $this->flushSession();
        $this->actingAs($member)
            ->getJson('/_test/active-business')
            ->assertForbidden();

        // Request 3: header selects B cleanly.
        $this->actingAs($member)
            ->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->getJson('/_test/active-business')
            ->assertOk()
            ->assertJson(['business_id' => $this->businessB->id]);

        // Request 4: header flushed -> fail closed again.
        $this->flushHeaders();
        $this->actingAs($member)
            ->getJson('/_test/active-business')
            ->assertForbidden();
    }

    public function test_disabling_tenancy_keeps_legacy_context_free_behaviour(): void
    {
        config()->set('multi_business.enabled', false);

        $this->actingAs($this->admin)
            ->getJson('/_test/active-business')
            ->assertOk()
            ->assertJson(['business_id' => null]);
    }
}
