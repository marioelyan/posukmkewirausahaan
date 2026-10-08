<?php

namespace Tests\Feature\CashierShifts;

use App\Models\CashierShift;
use App\Models\ShiftCashMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo(['cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close']);
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->shift = app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            100000,
            null,
            $this->warehouse->id
        );
    }

    public function test_cashier_can_record_cash_in_and_out(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('cashier-shifts.cash-movements.store', $this->shift), [
                'type' => 'in',
                'amount' => 50000,
                'note' => 'Setoran tambahan',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->cashier)
            ->post(route('cashier-shifts.cash-movements.store', $this->shift), [
                'type' => 'out',
                'amount' => 20000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shift_cash_movements', [
            'cashier_shift_id' => $this->shift->id,
            'type' => 'in',
            'amount' => 50000,
        ]);
        $this->assertDatabaseHas('shift_cash_movements', [
            'cashier_shift_id' => $this->shift->id,
            'type' => 'out',
            'amount' => 20000,
        ]);
    }

    public function test_expected_cash_includes_cash_movements(): void
    {
        app(CashierShiftService::class)->recordCashMovement($this->shift, $this->cashier, 'in', 50000);
        app(CashierShiftService::class)->recordCashMovement($this->shift, $this->cashier, 'out', 20000);

        $summary = app(CashierShiftService::class)->calculateSummary($this->shift->fresh());

        $this->assertSame(50000, $summary['cash_in_total']);
        $this->assertSame(20000, $summary['cash_out_total']);
        // opening 100000 + in 50000 - out 20000 = 130000 (no sales/refunds)
        $this->assertSame(130000, $summary['expected_cash']);
    }

    public function test_movement_is_rejected_on_closed_shift(): void
    {
        app(CashierShiftService::class)->closeShift($this->shift, $this->cashier, 100000);

        $this->expectException(ValidationException::class);

        app(CashierShiftService::class)->recordCashMovement(
            $this->shift->fresh(),
            $this->cashier,
            'in',
            50000
        );
    }

    public function test_invalid_amount_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('cashier-shifts.cash-movements.store', $this->shift), [
                'type' => 'in',
                'amount' => 0,
            ])
            ->assertInvalid(['amount']);

        $this->actingAs($this->cashier)
            ->post(route('cashier-shifts.cash-movements.store', $this->shift), [
                'type' => 'sideways',
                'amount' => 5000,
            ])
            ->assertInvalid(['type']);

        $this->assertSame(0, ShiftCashMovement::count());
    }

    public function test_other_cashier_cannot_record_movement_on_foreign_shift(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo(['cashier-shifts-access']);
        $other->markEmailAsVerified();

        $this->actingAs($other)
            ->post(route('cashier-shifts.cash-movements.store', $this->shift), [
                'type' => 'in',
                'amount' => 50000,
            ])
            ->assertNotFound();

        $this->assertSame(0, ShiftCashMovement::count());
    }

    public function test_shift_report_renders_for_owner(): void
    {
        app(CashierShiftService::class)->recordCashMovement($this->shift, $this->cashier, 'in', 50000);

        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.report', ['cashierShift' => $this->shift, 'type' => 'x']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');

        $closed = app(CashierShiftService::class)->closeShift($this->shift->fresh(), $this->cashier, 150000);

        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.report', ['cashierShift' => $closed, 'type' => 'z']))
            ->assertOk();
    }

    public function test_invalid_report_type_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.report', ['cashierShift' => $this->shift, 'type' => 'w']))
            ->assertNotFound();
    }
}
