<?php

namespace Tests\Feature\Finance;

use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\ShiftCashMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 slice 3 (§3.6 coverage audit, as tests): every supplier-payment
 * path journals exactly once, no gaps, no doubles.
 *
 * Path 1 — PayableController::pay (web, any media): journals via the
 * PayablePayment row (slice 2).
 * Path 2 — drawer pelunasan_hutang via CashierShiftService: journals via
 * the shift movement; the PayablePayment mirror row it creates must NOT
 * carry its own journal.
 * No other PayablePayment creation site exists in the codebase (verified
 * by grep: only PayableController::pay and the CashierShiftService mirror).
 */
class PayablePaymentCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();
        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Supplier Uji', 'phone' => '081234567890']);
    }

    private function payable(int $total = 100000, int $paid = 0): Payable
    {
        return Payable::create([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'DOC-'.uniqid(),
            'total' => $total,
            'paid' => $paid,
            'status' => 'unpaid',
        ]);
    }

    private function assertJournalLines(JournalEntry $entry, array $expected): void
    {
        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);
        sort($expected);

        $this->assertSame($expected, $lines);
    }

    public function test_pay_controller_path_journals_exactly_once(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->admin)->post(route('payables.pay', $payable), [
            'amount' => 40000,
            'paid_at' => now()->toDateString(),
            'method' => 'transfer',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(1, JournalEntry::count());
        $entry = JournalEntry::where('source_type', 'PayablePayment')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['2100', 40000, 0],
            ['1110', 0, 40000],
        ]);
    }

    public function test_drawer_pelunasan_journals_once_via_movement_not_mirror_row(): void
    {
        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            100000,
            null,
            $this->warehouse->id,
        );

        $shift = app(CashierShiftService::class)->getActiveShiftForUser($this->cashier->id);
        $payable = $this->payable();

        $movement = app(CashierShiftService::class)->recordCashMovement(
            $shift,
            $this->cashier,
            ShiftCashMovement::TYPE_OUT,
            40000,
            null,
            'pelunasan_hutang',
            null,
            $payable->id,
        );

        // Exactly one journal for the whole economic event, keyed on the
        // movement — and nothing keyed on the mirror PayablePayment row.
        $this->assertSame(1, JournalEntry::count());
        $entry = JournalEntry::where('source_type', 'ShiftCashMovement')
            ->where('source_id', $movement->id)
            ->firstOrFail();
        $this->assertJournalLines($entry, [
            ['2100', 40000, 0],
            ['1100', 0, 40000],
        ]);
        $this->assertSame(0, JournalEntry::where('source_type', 'PayablePayment')->count());

        $this->assertDatabaseHas('payables', [
            'id' => $payable->id,
            'paid' => 40000,
            'status' => 'partial',
        ]);
    }

    public function test_cash_pay_controller_path_credits_kas(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->admin)->post(route('payables.pay', $payable), [
            'amount' => 25000,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionDoesntHaveErrors();

        $entry = JournalEntry::where('source_type', 'PayablePayment')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['2100', 25000, 0],
            ['1100', 0, 25000],
        ]);
    }
}
