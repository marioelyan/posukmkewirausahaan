<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftCashMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected static int $seq = 0;

    protected User $cashier;

    protected User $admin;

    protected Warehouse $warehouse;

    protected ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();
        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

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

    protected function uniqueName(string $prefix): string
    {
        return $prefix.' '.(++self::$seq).' '.uniqid();
    }

    public function test_cashier_can_create_bank_expense(): void
    {
        $response = $this->actingAs($this->cashier)->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'amount' => 50000,
            'source' => 'bank',
            'note' => 'Beli galon',
        ]);

        $response->assertRedirect(route('expenses.index'));
        $this->assertDatabaseHas('expenses', [
            'expense_category_id' => $this->category->id,
            'amount' => 50000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_direct_drawer_expense_is_rejected(): void
    {
        $response = $this->actingAs($this->cashier)->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 50000,
            'source' => 'drawer',
        ]);

        $response->assertSessionHasErrors('source');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_expense_source_is_immutable_on_update(): void
    {
        $expense = Expense::create([
            'expense_category_id' => $this->category->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'amount' => 50000,
            'source' => 'bank',
            'note' => 'Awal',
            'user_id' => $this->cashier->id,
        ]);

        // Sending a source on update is a validation error, not a silent ignore.
        $response = $this->actingAs($this->cashier)->put(route('expenses.update', $expense->id), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 75000,
            'source' => 'other_cash',
            'note' => 'Diubah',
        ]);

        $response->assertSessionHasErrors('source');
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'amount' => 50000,
            'source' => 'bank',
            'note' => 'Awal',
        ]);

        // A valid update without source changes the editable fields only.
        $response = $this->actingAs($this->cashier)->put(route('expenses.update', $expense->id), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 75000,
            'note' => 'Diubah',
        ]);

        $response->assertRedirect(route('expenses.index'));
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'amount' => 75000,
            'source' => 'bank',
            'note' => 'Diubah',
        ]);
    }

    public function test_deleting_expense_keeps_cash_movement(): void
    {
        $shift = app(CashierShiftService::class)
            ->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);

        $expense = Expense::create([
            'expense_category_id' => $this->category->id,
            'cashier_shift_id' => $shift->id,
            'date' => now()->toDateString(),
            'amount' => 20000,
            'source' => 'drawer',
            'user_id' => $this->cashier->id,
        ]);

        $movement = ShiftCashMovement::create([
            'cashier_shift_id' => $shift->id,
            'type' => 'out',
            'kind' => 'beban',
            'expense_id' => $expense->id,
            'amount' => 20000,
            'note' => 'Beban laci',
            'user_id' => $this->cashier->id,
        ]);

        $response = $this->actingAs($this->cashier)->delete(route('expenses.destroy', $expense->id));

        $response->assertRedirect(route('expenses.index'));
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
        // The movement row survives; its expense link is nulled by the FK.
        $this->assertDatabaseHas('shift_cash_movements', [
            'id' => $movement->id,
            'kind' => 'beban',
            'amount' => 20000,
            'expense_id' => null,
        ]);
    }

    public function test_category_in_use_cannot_be_deleted(): void
    {
        Expense::create([
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 10000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('expense-categories.destroy', $this->category->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('expense_categories', ['id' => $this->category->id]);
    }

    public function test_user_without_permission_cannot_create_expense(): void
    {
        $plain = User::factory()->create(['name' => $this->uniqueName('Plain')]);
        $plain->markEmailAsVerified();

        $response = $this->actingAs($plain)->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'date' => now()->toDateString(),
            'amount' => 10000,
            'source' => 'bank',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_cashier_can_open_expense_index(): void
    {
        $response = $this->actingAs($this->cashier)->get(route('expenses.index'));

        $response->assertOk();
    }
}
