<?php

namespace Tests\Feature\Finance;

use App\Models\CashierShift;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 slice 2: jurnal selisih kas saat tutup shift (spec §3.9).
 *
 * Kurang: Dr 5280 / Cr 1100. Lebih: Dr 1100 / Cr 4200. Nol: tanpa jurnal.
 * Dijurnal dalam transaksi penutupan yang sama, satu jurnal per shift.
 */
class CashDifferenceJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function openShift(int $openingCash = 100000): CashierShift
    {
        return app(CashierShiftService::class)->openShift(
            $this->cashier,
            $this->cashier,
            $openingCash,
            null,
            $this->warehouse->id,
        );
    }

    private function assertShiftJournal(CashierShift $shift, array $expected): void
    {
        $entry = JournalEntry::where('source_type', 'CashierShift')
            ->where('source_id', $shift->id)
            ->firstOrFail();

        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);
        sort($expected);

        $this->assertSame($expected, $lines);
    }

    public function test_short_cash_journals_debit_5280(): void
    {
        $shift = $this->openShift();

        $closed = app(CashierShiftService::class)->closeShift($shift, $this->cashier, 90000);

        $this->assertSame(-10000, (int) $closed->cash_difference);
        $this->assertShiftJournal($closed, [
            ['5280', 10000, 0],
            ['1100', 0, 10000],
        ]);
    }

    public function test_over_cash_journals_credit_4200(): void
    {
        $shift = $this->openShift();

        $closed = app(CashierShiftService::class)->closeShift($shift, $this->cashier, 115000);

        $this->assertSame(15000, (int) $closed->cash_difference);
        $this->assertShiftJournal($closed, [
            ['1100', 15000, 0],
            ['4200', 0, 15000],
        ]);
    }

    public function test_zero_difference_creates_no_journal(): void
    {
        $shift = $this->openShift();

        $closed = app(CashierShiftService::class)->closeShift($shift, $this->cashier, 100000);

        $this->assertSame(0, (int) $closed->cash_difference);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_short_cash_after_beban_movement_uses_net_difference(): void
    {
        $shift = $this->openShift();

        $category = ExpenseCategory::create([
            'name' => 'Perlengkapan',
            'is_active' => true,
        ]);

        app(CashierShiftService::class)->recordCashMovement(
            $shift, $this->cashier, 'out', 20000, 'Beli galon', 'beban', $category->id
        );

        // Expected 80000 (100000 - 20000); actual 75000 -> short 5000.
        $closed = app(CashierShiftService::class)->closeShift($shift, $this->cashier, 75000);

        $this->assertSame(-5000, (int) $closed->cash_difference);
        $this->assertShiftJournal($closed, [
            ['5280', 5000, 0],
            ['1100', 0, 5000],
        ]);
        // Beban journal + cash-difference journal, nothing else.
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_cash_difference_journal_is_idempotent(): void
    {
        $shift = $this->openShift();

        $closed = app(CashierShiftService::class)->closeShift($shift, $this->cashier, 90000);

        $service = app(JournalService::class);
        $first = $service->postCashDifferenceJournal($closed, null);
        $second = $service->postCashDifferenceJournal($closed, null);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('journal_entries', 1);
    }
}
