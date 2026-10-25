<?php

namespace Tests\Feature\Finance;

use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\ShiftCashMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 slice 2: jurnal gerakan kas shift (spec §3.8, keputusan Q1 + Q5).
 *
 * Setiap kind dijurnal tepat sekali dari dokumen sumbernya; beban tidak
 * pernah dijurnal lewat movement (sudah lewat Expense); setoran lama tanpa
 * tujuan tidak dijurnal.
 */
class MovementJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected User $admin;

    protected Warehouse $warehouse;

    protected BankAccount $bank;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();
        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->bank = BankAccount::create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Toko Test',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create(['name' => 'Ibu Ani', 'phone' => '081234567890']);

        app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            100000,
            null,
            $this->warehouse->id,
        );
    }

    private function shift(): CashierShift
    {
        return app(CashierShiftService::class)->getActiveShiftForUser($this->cashier->id);
    }

    private function move(string $type, string $kind, int $amount, array $extra = []): ShiftCashMovement
    {
        return app(CashierShiftService::class)->recordCashMovement(
            $this->shift(),
            $this->cashier,
            $type,
            $amount,
            $extra['note'] ?? null,
            $kind,
            $extra['expense_category_id'] ?? null,
            $extra['payable_id'] ?? null,
            $extra['setoran_destination'] ?? null,
            $extra['bank_account_id'] ?? null,
        );
    }

    private function assertMovementJournal(ShiftCashMovement $movement, array $expected): void
    {
        $entry = JournalEntry::where('source_type', 'ShiftCashMovement')
            ->where('source_id', $movement->id)
            ->firstOrFail();

        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);
        sort($expected);

        $this->assertSame($expected, $lines);
    }

    private function makePayable(array $overrides = []): Payable
    {
        return Payable::create(array_merge([
            'supplier_id' => $this->supplier->id,
            'document_number' => 'DOC-'.uniqid(),
            'total' => 100000,
            'paid' => 0,
            'status' => 'unpaid',
        ], $overrides));
    }

    public function test_setoran_to_bank_journals_debit_1110(): void
    {
        $movement = $this->move('out', 'setoran', 50000, [
            'setoran_destination' => 'bank',
            'bank_account_id' => $this->bank->id,
        ]);

        $this->assertDatabaseHas('shift_cash_movements', [
            'id' => $movement->id,
            'setoran_destination' => 'bank',
            'bank_account_id' => $this->bank->id,
        ]);
        $this->assertMovementJournal($movement, [
            ['1110', 50000, 0],
            ['1100', 0, 50000],
        ]);
    }

    public function test_setoran_to_owner_journals_debit_3110_prive(): void
    {
        $movement = $this->move('out', 'setoran', 40000, ['setoran_destination' => 'owner']);

        $this->assertMovementJournal($movement, [
            ['3110', 40000, 0],
            ['1100', 0, 40000],
        ]);
    }

    public function test_setoran_masuk_journals_debit_1100_credit_1110(): void
    {
        $movement = $this->move('in', 'setoran_masuk', 60000);

        $this->assertMovementJournal($movement, [
            ['1100', 60000, 0],
            ['1110', 0, 60000],
        ]);
    }

    public function test_talangan_out_and_in_are_mirror_journals(): void
    {
        $out = $this->move('out', 'talangan', 30000);
        $in = $this->move('in', 'talangan_masuk', 30000);

        $this->assertMovementJournal($out, [
            ['1130', 30000, 0],
            ['1100', 0, 30000],
        ]);
        // Locked Q1: talangan_masuk repays the advance.
        $this->assertMovementJournal($in, [
            ['1100', 30000, 0],
            ['1130', 0, 30000],
        ]);
    }

    public function test_pelunasan_supplier_payable_journals_debit_2100(): void
    {
        $payable = $this->makePayable();

        $movement = $this->move('out', 'pelunasan_hutang', 40000, ['payable_id' => $payable->id]);

        $this->assertMovementJournal($movement, [
            ['2100', 40000, 0],
            ['1100', 0, 40000],
        ]);
        $this->assertSame(40000, (int) $payable->fresh()->paid);
    }

    public function test_pelunasan_consignment_payable_journals_debit_2110(): void
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-KON-'.uniqid(),
            'cash' => 50000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 50000,
        ]);

        $payable = $this->makePayable([
            'transaction_id' => $transaction->id,
            'document_number' => 'KON-TRX-KON-S1',
            'total' => 30000,
        ]);

        $movement = $this->move('out', 'pelunasan_hutang', 30000, ['payable_id' => $payable->id]);

        $this->assertMovementJournal($movement, [
            ['2110', 30000, 0],
            ['1100', 0, 30000],
        ]);
    }

    public function test_bank_payment_via_pay_journals_credit_1110(): void
    {
        $payable = $this->makePayable();

        $response = $this->actingAs($this->admin)->post(route('payables.pay', $payable), [
            'amount' => 60000,
            'paid_at' => now()->toDateString(),
            'method' => 'transfer',
            'bank_account_id' => $this->bank->id,
        ]);

        $response->assertRedirect();

        $payment = $payable->payments()->firstOrFail();
        $entry = JournalEntry::where('source_type', 'PayablePayment')
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);

        $this->assertSame([['1110', 0, 60000], ['2100', 60000, 0]], $lines);
    }

    public function test_lainnya_and_modal_journals(): void
    {
        $in = $this->move('in', 'lainnya', 15000);
        $out = $this->move('out', 'lainnya', 7000);
        $modal = $this->move('in', 'modal', 100000);

        $this->assertMovementJournal($in, [
            ['1100', 15000, 0],
            ['4200', 0, 15000],
        ]);
        $this->assertMovementJournal($out, [
            ['5270', 7000, 0],
            ['1100', 0, 7000],
        ]);
        $this->assertMovementJournal($modal, [
            ['1100', 100000, 0],
            ['3100', 0, 100000],
        ]);
    }

    public function test_movement_journal_is_idempotent(): void
    {
        $movement = $this->move('in', 'modal', 50000);

        $service = app(JournalService::class);
        $first = $service->postMovementJournal($movement, null);
        $second = $service->postMovementJournal($movement, null);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_legacy_setoran_without_destination_is_not_journaled(): void
    {
        $movement = ShiftCashMovement::create([
            'cashier_shift_id' => $this->shift()->id,
            'type' => 'out',
            'kind' => 'setoran',
            'setoran_destination' => null,
            'bank_account_id' => null,
            'amount' => 25000,
            'user_id' => $this->cashier->id,
        ]);

        $entry = app(JournalService::class)->postMovementJournal($movement, null);

        $this->assertNull($entry);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_web_setoran_without_destination_is_rejected(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift()->id),
            ['type' => 'out', 'kind' => 'setoran', 'amount' => 30000]
        );

        $response->assertSessionHasErrors('setoran_destination');
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_web_setoran_to_bank_without_account_is_rejected(): void
    {
        $response = $this->actingAs($this->cashier)->post(
            route('cashier-shifts.cash-movements.store', $this->shift()->id),
            ['type' => 'out', 'kind' => 'setoran', 'setoran_destination' => 'bank', 'amount' => 30000]
        );

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }

    public function test_api_setoran_without_destination_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $response = $this->postJson(route('api.pos.shift.cash-movements.store'), [
            'type' => 'out',
            'kind' => 'setoran',
            'amount' => 30000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('setoran_destination');
        $this->assertDatabaseCount('shift_cash_movements', 0);
    }
}
