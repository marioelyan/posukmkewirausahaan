<?php

namespace Tests\Feature\Finance;

use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fase 1 slice 2: jurnal beban (spec §3.7, §2.2).
 *
 * Debit 52xx per pemetaan kategori; kredit mengikuti source Expense:
 * drawer dan other_cash ke 1100 Kas, bank ke 1110 Bank.
 */
class ExpenseJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class, ExpenseCategorySeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

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

    private function assertJournalLines(JournalEntry $entry, array $expected): void
    {
        $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
            $line->account->code, (int) $line->debit, (int) $line->credit,
        ])->all();

        sort($lines);
        sort($expected);

        $this->assertSame($expected, $lines);
    }

    public function test_drawer_beban_journals_debit_52xx_credit_1100_once(): void
    {
        $category = ExpenseCategory::where('name', 'Perlengkapan')->firstOrFail();

        $movement = app(CashierShiftService::class)->recordCashMovement(
            $this->shift(), $this->cashier, 'out', 20000, 'Beli galon', 'beban', $category->id
        );

        $this->assertDatabaseCount('journal_entries', 1);

        $entry = JournalEntry::firstOrFail();
        $this->assertSame('Expense', $entry->source_type);
        $this->assertSame($movement->expense_id, (int) $entry->source_id);
        $this->assertJournalLines($entry, [
            ['5200', 20000, 0],
            ['1100', 0, 20000],
        ]);

        // The movement itself carries no journal: one event, one entry.
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'ShiftCashMovement',
            'source_id' => $movement->id,
        ]);
    }

    public function test_direct_bank_expense_journals_credit_1110(): void
    {
        $category = ExpenseCategory::where('name', 'Listrik & Air')->firstOrFail();

        $response = $this->actingAs($this->cashier)->post(route('expenses.store'), [
            'expense_category_id' => $category->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'amount' => 150000,
            'source' => 'bank',
            'note' => 'Bayar listrik',
        ]);

        $response->assertRedirect(route('expenses.index'));

        $entry = JournalEntry::firstOrFail();
        $this->assertSame('Expense', $entry->source_type);
        $this->assertJournalLines($entry, [
            ['5230', 150000, 0],
            ['1110', 0, 150000],
        ]);
    }

    public function test_direct_other_cash_expense_journals_credit_1100(): void
    {
        $category = ExpenseCategory::where('name', 'Transport & Pengiriman')->firstOrFail();

        $response = $this->actingAs($this->cashier)->post(route('expenses.store'), [
            'expense_category_id' => $category->id,
            'date' => now()->toDateString(),
            'amount' => 25000,
            'source' => 'other_cash',
        ]);

        $response->assertRedirect(route('expenses.index'));

        $entry = JournalEntry::firstOrFail();
        $this->assertJournalLines($entry, [
            ['5240', 25000, 0],
            ['1100', 0, 25000],
        ]);
    }

    public function test_all_seeded_categories_map_to_5200_5270(): void
    {
        $expected = [
            'Perlengkapan' => '5200',
            'Sewa Tempat' => '5210',
            'Gaji & Upah' => '5220',
            'Listrik & Air' => '5230',
            'Transport & Pengiriman' => '5240',
            'Pemasaran' => '5250',
            'Peralatan Kecil' => '5260',
            'Lainnya' => '5270',
        ];

        $service = app(JournalService::class);

        foreach ($expected as $name => $code) {
            $category = ExpenseCategory::where('name', $name)->firstOrFail();
            $this->assertSame($code, $service->expenseAccountCode($category));
        }
    }

    public function test_custom_category_with_valid_account_code_maps(): void
    {
        $category = ExpenseCategory::create([
            'name' => 'Kebersihan',
            'account_code' => '5240',
            'is_active' => true,
        ]);

        $expense = Expense::create([
            'expense_category_id' => $category->id,
            'date' => now()->toDateString(),
            'amount' => 10000,
            'source' => 'other_cash',
            'user_id' => $this->cashier->id,
        ]);

        $entry = app(JournalService::class)->postExpenseJournal($expense, null);

        $this->assertJournalLines($entry, [
            ['5240', 10000, 0],
            ['1100', 0, 10000],
        ]);
    }

    public function test_unmapped_category_cannot_be_used(): void
    {
        $category = ExpenseCategory::create([
            'name' => 'Tanpa Akun',
            'account_code' => null,
            'is_active' => true,
        ]);

        try {
            app(CashierShiftService::class)->recordCashMovement(
                $this->shift(), $this->cashier, 'out', 10000, 'X', 'beban', $category->id
            );
            $this->fail('Unmapped category should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expense_category_id', $e->errors());
        }

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('shift_cash_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_expense_journal_is_idempotent(): void
    {
        $category = ExpenseCategory::where('name', 'Pemasaran')->firstOrFail();

        $expense = Expense::create([
            'expense_category_id' => $category->id,
            'date' => now()->toDateString(),
            'amount' => 40000,
            'source' => 'bank',
            'user_id' => $this->cashier->id,
        ]);

        $service = app(JournalService::class);
        $first = $service->postExpenseJournal($expense, null);
        $second = $service->postExpenseJournal($expense, null);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('journal_entries', 1);
    }
}
