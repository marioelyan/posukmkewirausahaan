<?php

namespace Tests\Feature\CashierShifts;

use App\Models\CashierShift;
use App\Models\Payable;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * K3 Konsinyasi: pembayaran hutang.
 *
 * Bank lewat PayableController::pay yang sudah ada (diuji satu jalur).
 * Laci lewat gerakan kas pelunasan_hutang: satu peristiwa, dua catatan
 * (gerakan kas + pembayaran hutang), bukan beban.
 */
class ConsignmentPayablePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected CashierShift $shift;

    protected Supplier $supplier;

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

        $this->shift = app(CashierShiftService::class)
            ->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);

        $this->supplier = Supplier::create(['name' => 'Ibu Ani', 'phone' => '081234567890']);
    }

    private function makePayable(int $total = 25000, int $paid = 0): Payable
    {
        return Payable::create([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'KON-TRX-TEST-S'.$this->supplier->id,
            'total' => $total,
            'paid' => $paid,
            'status' => $paid > 0 ? ($paid >= $total ? 'paid' : 'partial') : 'unpaid',
            'note' => 'Konsinyasi dari penjualan TRX-TEST',
        ]);
    }

    public function test_drawer_payment_creates_movement_and_payment_and_updates_payable(): void
    {
        $payable = $this->makePayable();

        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'payable_id' => $payable->id,
                'amount' => 10000,
                'note' => 'Bayar Ibu Ani',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('shift_cash_movements', [
            'cashier_shift_id' => $this->shift->id,
            'type' => 'out',
            'kind' => 'pelunasan_hutang',
            'payable_id' => $payable->id,
            'amount' => 10000,
        ]);
        $this->assertDatabaseHas('payable_payments', [
            'payable_id' => $payable->id,
            'amount' => 10000,
            'method' => 'cash',
            'bank_account_id' => null,
        ]);
        $this->assertDatabaseHas('payables', [
            'id' => $payable->id,
            'total' => 25000,
            'paid' => 10000,
            'status' => 'partial',
        ]);

        // Kas laci yang diharapkan ikut berkurang (gerakan out tercatat).
        $summary = app(CashierShiftService::class)->calculateSummary($this->shift->fresh());
        $this->assertSame(90000, $summary['expected_cash']);
    }

    public function test_drawer_payment_in_full_marks_payable_paid(): void
    {
        $payable = $this->makePayable();

        $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'payable_id' => $payable->id,
                'amount' => 25000,
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('payables', [
            'id' => $payable->id,
            'paid' => 25000,
            'status' => 'paid',
        ]);
    }

    public function test_drawer_payment_above_remaining_is_rejected(): void
    {
        $payable = $this->makePayable(total: 25000, paid: 20000);

        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'payable_id' => $payable->id,
                'amount' => 10000,
            ]
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('shift_cash_movements', 0);
        $this->assertDatabaseCount('payable_payments', 0);
        $this->assertDatabaseHas('payables', ['id' => $payable->id, 'paid' => 20000]);
    }

    public function test_pelunasan_without_payable_is_rejected(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'amount' => 10000,
            ]
        );

        $response->assertSessionHasErrors('payable_id');
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_fully_paid_payable_cannot_be_paid_again_from_drawer(): void
    {
        $payable = $this->makePayable(total: 25000, paid: 25000);

        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'payable_id' => $payable->id,
                'amount' => 1000,
            ]
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('shift_cash_movements', 0);
        $this->assertDatabaseCount('payable_payments', 0);
    }

    public function test_api_drawer_payment_works(): void
    {
        Sanctum::actingAs($this->cashier, ['pos-access']);

        $payable = $this->makePayable();

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'type' => 'out',
            'kind' => 'pelunasan_hutang',
            'payable_id' => $payable->id,
            'amount' => 15000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payable_id', $payable->id);
        $this->assertDatabaseHas('payable_payments', [
            'payable_id' => $payable->id,
            'amount' => 15000,
            'method' => 'cash',
        ]);
        $this->assertDatabaseHas('payables', [
            'id' => $payable->id,
            'paid' => 15000,
            'status' => 'partial',
        ]);
    }

    public function test_bank_pay_still_works_for_consignment_payable(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();

        $payable = $this->makePayable();

        $response = $this->actingAs($admin)->post(
            route('payables.pay', $payable),
            [
                'amount' => 25000,
                'paid_at' => now()->toDateString(),
                'method' => 'bank_transfer',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('payables', [
            'id' => $payable->id,
            'paid' => 25000,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payable_payments', [
            'payable_id' => $payable->id,
            'amount' => 25000,
            'method' => 'bank_transfer',
        ]);
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_drawer_payment_is_not_an_expense(): void
    {
        $payable = $this->makePayable();

        $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift->id),
            [
                'type' => 'out',
                'kind' => 'pelunasan_hutang',
                'payable_id' => $payable->id,
                'amount' => 10000,
            ]
        )->assertRedirect();

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseHas('shift_cash_movements', [
            'payable_id' => $payable->id,
            'expense_id' => null,
        ]);
    }
}
