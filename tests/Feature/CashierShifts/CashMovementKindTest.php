<?php

namespace Tests\Feature\CashierShifts;

use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftCashMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\ExpenseReportService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementKindTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected ExpenseCategory $category;

    protected CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();

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

        $this->shift = app(CashierShiftService::class)
            ->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
    }

    public function test_out_beban_creates_exactly_one_expense_and_one_movement(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'beban',
                'expense_category_id' => $this->category->id,
                'amount' => 20000,
                'note' => 'Beli galon',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('shift_cash_movements', 1);
        $this->assertDatabaseHas('expenses', [
            'expense_category_id' => $this->category->id,
            'cashier_shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'amount' => 20000,
            'source' => 'drawer',
            'note' => 'Beli galon',
        ]);
        $this->assertDatabaseHas('shift_cash_movements', [
            'cashier_shift_id' => $this->shift->id,
            'type' => 'out',
            'kind' => 'beban',
            'amount' => 20000,
        ]);
        $movement = ShiftCashMovement::first();
        $this->assertNotNull($movement->expense_id);
        $this->assertSame(Expense::first()->id, $movement->expense_id);
    }

    public function test_out_setoran_creates_no_expense(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'setoran',
                'amount' => 30000,
                'note' => 'Setor ke pusat',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseHas('shift_cash_movements', [
            'type' => 'out',
            'kind' => 'setoran',
            'amount' => 30000,
            'expense_id' => null,
        ]);
    }

    public function test_out_beban_without_category_is_rejected(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'beban',
                'amount' => 20000,
            ]
        );

        $response->assertSessionHasErrors('expense_category_id');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_out_lainnya_without_category_is_allowed(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'lainnya',
                'amount' => 5000,
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseHas('shift_cash_movements', [
            'type' => 'out',
            'kind' => 'lainnya',
            'amount' => 5000,
        ]);
    }

    public function test_close_shift_formula_unchanged_with_beban_movement(): void
    {
        app(CashierShiftService::class)->recordCashMovement(
            $this->shift, $this->cashier, 'out', 20000, 'Beli galon', 'beban', $this->category->id
        );

        $closed = app(CashierShiftService::class)->closeShift($this->shift, $this->cashier, 80000);

        // opening 100000 - beban out 20000 = expected 80000, actual 80000.
        $this->assertSame(80000, (int) $closed->expected_cash);
        $this->assertSame(0, (int) $closed->cash_difference);
    }

    public function test_legacy_null_kind_movement_never_counts_as_expense(): void
    {
        ShiftCashMovement::create([
            'cashier_shift_id' => $this->shift->id,
            'type' => 'out',
            'kind' => null,
            'amount' => 15000,
            'note' => 'Baris lama',
            'user_id' => $this->cashier->id,
        ]);

        $this->assertDatabaseCount('expenses', 0);

        $summary = app(ExpenseReportService::class)->expensesByCategory(null, null);
        $this->assertSame(0, $summary['total']);
        $this->assertSame([], $summary['rows']);
    }
}
