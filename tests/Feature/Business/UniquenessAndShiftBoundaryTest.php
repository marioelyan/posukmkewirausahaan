<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Architectural-decision-phase safaris:
 *
 *  - ADR-03: `categories.name` uniqueness must not leak across businesses.
 *    The API now scopes the `unique` rule with `TenantUnique` (there is no
 *    DB unique index on `categories.name`, so no migration is required).
 *  - Membership/boundary consistency: the web cashier-shift open path now
 *    rejects a warehouse id that does not belong to the active business,
 *    matching the rule already enforced on the API path.
 */
class UniquenessAndShiftBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $admin;

    private Outlet $outletA;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OA',
            'name' => 'Outlet A',
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

        // Business B intentionally has no active outlet. Only A's outlet is
        // active, which is exactly the condition that used to let the legacy
        // single-outlet bypass accept a foreign warehouse id.
        $this->warehouseB = Warehouse::create([
            'business_id' => $this->businessB->id,
            'outlet_id' => null,
            'code' => 'WB',
            'name' => 'Warehouse B',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(BusinessContext::class)->clear();
    }

    private function categoryFor(Business $business, string $name): Category
    {
        // Context is clear here, so the creating hook leaves business_id alone.
        return Category::create([
            'name' => $name,
            'description' => $name,
            'business_id' => $business->id,
        ]);
    }

    private function asApiBusinessA(array $abilities = ['*'])
    {
        Sanctum::actingAs($this->admin, $abilities);

        return $this->withHeader('X-Business-Id', (string) $this->businessA->id);
    }

    // -----------------------------------------------------------------
    // ADR-03 — tenant-scoped category name uniqueness
    // -----------------------------------------------------------------

    public function test_category_name_may_repeat_across_businesses_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $this->categoryFor($this->businessB, 'Elektronik');

        $this->asApiBusinessA()
            ->postJson('/api/v1/categories', ['name' => 'Elektronik'])
            ->assertStatus(201);

        $this->assertSame(2, Category::withoutGlobalScopes()->where('name', 'Elektronik')->count());
    }

    public function test_category_name_duplicate_inside_same_business_is_rejected(): void
    {
        config()->set('multi_business.enabled', true);

        $this->categoryFor($this->businessA, 'Elektronik');

        $this->asApiBusinessA()
            ->postJson('/api/v1/categories', ['name' => 'Elektronik'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_category_name_duplicate_is_rejected_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        Category::create(['name' => 'Elektronik', 'description' => 'global']);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/categories', ['name' => 'Elektronik'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_category_update_ignores_name_owned_by_other_business(): void
    {
        config()->set('multi_business.enabled', true);

        $categoryA = $this->categoryFor($this->businessA, 'Alpha');
        $this->categoryFor($this->businessB, 'Beta');

        $this->asApiBusinessA()
            ->patchJson("/api/v1/categories/{$categoryA->id}", ['name' => 'Beta'])
            ->assertOk();

        $this->assertDatabaseHas('categories', ['id' => $categoryA->id, 'name' => 'Beta']);
    }

    public function test_category_update_rejects_name_duplicate_inside_same_business(): void
    {
        config()->set('multi_business.enabled', true);

        $categoryA = $this->categoryFor($this->businessA, 'Alpha');
        $this->categoryFor($this->businessA, 'Beta');

        $this->asApiBusinessA()
            ->patchJson("/api/v1/categories/{$categoryA->id}", ['name' => 'Beta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // -----------------------------------------------------------------
    // ADR-03 — web category controller must not bypass the tenant-aware rule
    // -----------------------------------------------------------------

    private function asWebBusinessA()
    {
        return $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id]);
    }

    public function test_web_category_name_duplicate_inside_same_business_is_rejected(): void
    {
        config()->set('multi_business.enabled', true);

        $this->categoryFor($this->businessA, 'Elektronik');

        $this->asWebBusinessA()
            ->post(route('categories.store'), ['name' => 'Elektronik'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Category::withoutGlobalScopes()->where('name', 'Elektronik')->count());
    }

    public function test_web_category_name_may_repeat_across_businesses_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $this->categoryFor($this->businessB, 'Elektronik');

        $this->asWebBusinessA()
            ->post(route('categories.store'), ['name' => 'Elektronik'])
            ->assertRedirect(route('categories.index'));

        $this->assertSame(2, Category::withoutGlobalScopes()->where('name', 'Elektronik')->count());
    }

    public function test_web_category_update_ignores_name_owned_by_other_business(): void
    {
        config()->set('multi_business.enabled', true);

        $categoryA = $this->categoryFor($this->businessA, 'Alpha');
        $this->categoryFor($this->businessB, 'Beta');

        $this->asWebBusinessA()
            ->put(route('categories.update', $categoryA), ['name' => 'Beta'])
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', ['id' => $categoryA->id, 'name' => 'Beta']);
    }

    public function test_web_category_name_duplicate_is_rejected_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        Category::create(['name' => 'Elektronik', 'description' => 'global']);

        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Elektronik'])
            ->assertSessionHasErrors('name');
    }

    // -----------------------------------------------------------------
    // Boundary consistency — web cashier shift open rejects foreign warehouse
    // -----------------------------------------------------------------

    public function test_web_open_shift_rejects_foreign_warehouse_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('cashier-shifts.store'), [
                'opening_cash' => 100000,
                'warehouse_id' => $this->warehouseB->id,
            ])
            ->assertSessionHasErrors('warehouse_id');

        $this->assertDatabaseMissing('cashier_shifts', [
            'user_id' => $this->admin->id,
            'warehouse_id' => null,
        ]);
    }

    public function test_web_open_shift_accepts_own_warehouse_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('cashier-shifts.store'), [
                'opening_cash' => 100000,
                'warehouse_id' => $this->warehouseA->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cashier_shifts', [
            'user_id' => $this->admin->id,
            'warehouse_id' => $this->warehouseA->id,
        ]);
    }

    public function test_web_open_shift_warehouse_rule_is_inert_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $this->actingAs($this->admin)
            ->post(route('cashier-shifts.store'), [
                'opening_cash' => 50000,
                'warehouse_id' => $this->warehouseA->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cashier_shifts', [
            'user_id' => $this->admin->id,
            'warehouse_id' => $this->warehouseA->id,
        ]);
    }
}
