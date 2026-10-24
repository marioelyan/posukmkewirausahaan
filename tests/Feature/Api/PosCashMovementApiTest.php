<?php

namespace Tests\Feature\Api;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosCashMovementApiTest extends TestCase
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

    public function test_pos_beban_uses_active_shift_and_creates_expense(): void
    {
        app(CashierShiftService::class)
            ->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);

        Sanctum::actingAs($this->cashier, ['pos-access']);

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'type' => 'out',
            'kind' => 'beban',
            'expense_category_id' => $this->category->id,
            'amount' => 25000,
            'note' => 'Beli plastik',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseHas('expenses', [
            'amount' => 25000,
            'source' => 'drawer',
        ]);
        $this->assertDatabaseHas('shift_cash_movements', [
            'type' => 'out',
            'kind' => 'beban',
            'amount' => 25000,
        ]);
    }

    public function test_pos_beban_without_active_shift_returns_422(): void
    {
        Sanctum::actingAs($this->cashier, ['pos-access']);

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'type' => 'out',
            'kind' => 'beban',
            'expense_category_id' => $this->category->id,
            'amount' => 25000,
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_explicit_shift_of_another_cashier_is_forbidden(): void
    {
        $otherShift = app(CashierShiftService::class)
            ->openShift($this->admin, $this->admin, 100000, null, $this->warehouse->id);

        Sanctum::actingAs($this->cashier, ['pos-access']);

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'cashier_shift_id' => $otherShift->id,
            'type' => 'out',
            'kind' => 'lainnya',
            'amount' => 5000,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_token_without_pos_access_is_forbidden(): void
    {
        app(CashierShiftService::class)
            ->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);

        Sanctum::actingAs($this->cashier, ['user:read']);

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'type' => 'out',
            'kind' => 'lainnya',
            'amount' => 5000,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }
}
