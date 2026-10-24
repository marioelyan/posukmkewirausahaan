<?php

namespace Tests\Feature\Api;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected User $admin;

    protected ExpenseCategory $category;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->category = ExpenseCategory::create([
            'name' => 'Perlengkapan',
            'account_code' => '5101',
            'is_active' => true,
        ]);
    }

    public function test_index_requires_access_ability(): void
    {
        Sanctum::actingAs($this->cashier, ['expenses-access']);

        $response = $this->getJson(route('api.expenses.index'));

        $response->assertOk();
    }

    public function test_store_with_create_ability_succeeds(): void
    {
        Sanctum::actingAs($this->cashier, ['expenses-create']);

        $response = $this->postJson(route('api.expenses.store'), [
            'expense_category_id' => $this->category->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'amount' => 40000,
            'source' => 'bank',
            'note' => 'Beli tisu',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('expenses', [
            'amount' => 40000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_store_drawer_source_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier, ['expenses-create']);

        $response = $this->postJson(route('api.expenses.store'), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 40000,
            'source' => 'drawer',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_token_without_write_ability_is_forbidden(): void
    {
        Sanctum::actingAs($this->cashier, ['expenses-access']);

        $response = $this->postJson(route('api.expenses.store'), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 40000,
            'source' => 'bank',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_super_admin_without_ability_is_forbidden(): void
    {
        // Sanctum checks token abilities only — no super-admin bypass.
        Sanctum::actingAs($this->admin, ['user:read']);

        $response = $this->postJson(route('api.expenses.store'), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 40000,
            'source' => 'bank',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_category_destroy_in_use_returns_422(): void
    {
        Expense::create([
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 10000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);

        Sanctum::actingAs($this->admin, ['expense-categories-delete']);

        $response = $this->deleteJson(route('api.expense-categories.destroy', $this->category->id));

        $response->assertUnprocessable();
        $this->assertDatabaseHas('expense_categories', ['id' => $this->category->id]);
    }

    public function test_category_destroy_unused_succeeds(): void
    {
        Sanctum::actingAs($this->admin, ['expense-categories-delete']);

        $response = $this->deleteJson(route('api.expense-categories.destroy', $this->category->id));

        $response->assertOk();
        $this->assertDatabaseMissing('expense_categories', ['id' => $this->category->id]);
    }
}
