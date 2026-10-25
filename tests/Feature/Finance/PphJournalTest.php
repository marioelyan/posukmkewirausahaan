<?php

namespace Tests\Feature\Finance;

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\JournalService;
use App\Services\PphService;
use Carbon\Carbon;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 slice 3: PPh Final UMKM 0,5% (spec §3.10, locked Q4 = manual).
 *
 * Omset = SUM(grand_total) transaksi lunas pada bulan itu (basis yang
 * sama dengan laporan lite dan sheet). Akuntansi: Dr 5300 / Cr 2120
 * saat akrual, Dr 2120 / Cr 1100 (tunai) atau 1110 (bank) saat dibayar.
 * Satu jurnal hidup per (business, bulan); posting kedua ditolak
 * kecuali jurnal pertama sudah dibalik.
 */
class PphJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccountSeeder::class]);

        $this->cashier = User::factory()->create();
    }

    private function business(string $slug): Business
    {
        return Business::create([
            'name' => 'Bisnis '.$slug,
            'slug' => $slug,
            'owner_user_id' => $this->cashier->id,
            'status' => 'active',
        ]);
    }

    private function warehouse(Business $business): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-'.Str::upper(Str::random(5)),
            'name' => 'Gudang '.$business->slug,
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
            'business_id' => $business->id,
        ]);
    }

    private function shift(Business $business): CashierShift
    {
        return CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
            'business_id' => $business->id,
        ]);
    }

    private function paidTransaction(
        int $grandTotal,
        string $createdAt,
        ?CashierShift $shift = null,
        ?Warehouse $warehouse = null,
        string $paymentMethod = 'cash',
        string $paymentStatus = 'paid',
    ): Transaction {
        $transaction = Transaction::create([
            'cashier_id' => $this->cashier->id,
            'cashier_shift_id' => $shift?->id,
            'warehouse_id' => $warehouse?->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => $grandTotal,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $grandTotal,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
        ]);

        $transaction->created_at = Carbon::parse($createdAt);
        $transaction->save();

        return $transaction;
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

    public function test_omset_counts_only_paid_transactions_in_month_and_business(): void
    {
        $businessA = $this->business('a');
        $businessB = $this->business('b');
        $warehouseA = $this->warehouse($businessA);
        $warehouseB = $this->warehouse($businessB);
        $shiftA = $this->shift($businessA);
        $shiftB = $this->shift($businessB);

        $this->paidTransaction(60000, '2026-10-05 10:00:00', $shiftA, $warehouseA);
        $this->paidTransaction(40000, '2026-10-06 10:00:00', $shiftB, $warehouseB);
        // Shiftless but warehoused: attributed through the warehouse.
        $this->paidTransaction(25000, '2026-10-07 10:00:00', null, $warehouseA);
        // Other month: excluded.
        $this->paidTransaction(99999, '2026-09-15 10:00:00', $shiftA, $warehouseA);
        // Unpaid credit sale: excluded until paid (lite timing).
        $this->paidTransaction(50000, '2026-10-08 10:00:00', $shiftA, $warehouseA, 'pay_later', 'unpaid');

        $service = app(PphService::class);

        $this->assertSame(85000, $service->omsetForMonth($businessA->id, '2026-10'));
        $this->assertSame(40000, $service->omsetForMonth($businessB->id, '2026-10'));
        $this->assertSame(125000, $service->omsetForMonth(null, '2026-10'));
        $this->assertSame(425, $service->taxForMonth($businessA->id, '2026-10'));
    }

    public function test_tax_rounds_half_up(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);

        $this->paidTransaction(653500, '2026-08-20 10:00:00', null, $warehouse);

        // 653500 * 0,5% = 3267,5 -> 3268, same as the trial sheet.
        $this->assertSame(3268, app(PphService::class)->taxForMonth($business->id, '2026-08'));
    }

    public function test_accrual_posts_balanced_entry_and_rejects_second_run(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-10-10 10:00:00', null, $warehouse);

        $service = app(PphService::class);
        $entry = $service->accrue($business->id, '2026-10', $this->cashier->id);

        $this->assertNotNull($entry);
        $this->assertSame('2026-10', $entry->period);
        $this->assertSame($business->id, $entry->business_id);
        $this->assertJournalLines($entry, [
            ['5300', 500, 0],
            ['2120', 0, 500],
        ]);

        try {
            $service->accrue($business->id, '2026-10', $this->cashier->id);
            $this->fail('Expected a second accrual for the same month to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, JournalEntry::where('source_type', 'pph_period')->count());
        }
    }

    public function test_zero_omset_month_posts_nothing(): void
    {
        $business = $this->business('a');

        $this->assertNull(app(PphService::class)->accrue($business->id, '2026-10', $this->cashier->id));
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_reversal_allows_reaccrual(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-10-10 10:00:00', null, $warehouse);

        $service = app(PphService::class);
        $first = $service->accrue($business->id, '2026-10', $this->cashier->id);

        app(JournalService::class)->reverse($first);

        $second = $service->accrue($business->id, '2026-10', $this->cashier->id);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $service->liveAccrual($business->id, '2026-10')->id);
        $this->assertSame(2, JournalEntry::where('source_type', 'pph_period')->count());
    }

    public function test_payment_posts_settlement_and_rejects_second_payment(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-10-10 10:00:00', null, $warehouse);

        $service = app(PphService::class);
        $service->accrue($business->id, '2026-10', $this->cashier->id);

        $payment = $service->pay($business->id, '2026-10', 'cash', $this->cashier->id);
        $this->assertJournalLines($payment, [
            ['2120', 500, 0],
            ['1100', 0, 500],
        ]);

        try {
            $service->pay($business->id, '2026-10', 'cash', $this->cashier->id);
            $this->fail('Expected a second payment for the same month to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, JournalEntry::where('source_type', 'pph_payment')->count());
        }
    }

    public function test_bank_payment_credits_bank_and_payment_without_accrual_is_rejected(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-10-10 10:00:00', null, $warehouse);

        $service = app(PphService::class);

        try {
            $service->pay($business->id, '2026-10', 'transfer', $this->cashier->id);
            $this->fail('Expected payment without an accrual to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, JournalEntry::count());
        }

        $service->accrue($business->id, '2026-10', $this->cashier->id);
        $payment = $service->pay($business->id, '2026-10', 'transfer', $this->cashier->id);
        $this->assertJournalLines($payment, [
            ['2120', 500, 0],
            ['1110', 0, 500],
        ]);
    }

    public function test_invalid_period_is_rejected(): void
    {
        try {
            app(PphService::class)->omsetForMonth(null, '2026-13');
            $this->fail('Expected an invalid month to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_command_accrues_and_pays(): void
    {
        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-11-10 10:00:00', null, $warehouse);

        $this->artisan('accounting:pph', [
            'month' => '2026-11',
            '--business' => $business->id,
        ])->assertExitCode(0);
        $this->assertSame(1, JournalEntry::where('source_type', 'pph_period')->count());

        // Duplicate month fails.
        $this->artisan('accounting:pph', [
            'month' => '2026-11',
            '--business' => $business->id,
        ])->assertExitCode(1);

        $this->artisan('accounting:pph', [
            'month' => '2026-11',
            '--business' => $business->id,
            '--pay' => true,
            '--method' => 'cash',
        ])->assertExitCode(0);
        $this->assertSame(1, JournalEntry::where('source_type', 'pph_payment')->count());
    }

    public function test_command_defaults_to_the_only_business(): void
    {
        $business = $this->business('only');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-11-10 10:00:00', null, $warehouse);

        $this->artisan('accounting:pph', ['month' => '2026-11'])->assertExitCode(0);

        $entry = JournalEntry::where('source_type', 'pph_period')->firstOrFail();
        $this->assertSame($business->id, $entry->business_id);
    }

    public function test_laba_rugi_button_posts_accrual(): void
    {
        Permission::firstOrCreate(['name' => 'reports-access', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('reports-access');
        $user->markEmailAsVerified();

        $business = $this->business('a');
        $warehouse = $this->warehouse($business);
        $this->paidTransaction(100000, '2026-10-10 10:00:00', null, $warehouse);

        $this->actingAs($user)
            ->post(route('reports.profit-loss.pph'), ['month' => '2026-10'])
            ->assertRedirect(route('reports.profit-loss.index'));

        $entry = JournalEntry::where('source_type', 'pph_period')->firstOrFail();
        $this->assertSame('2026-10', $entry->period);
        $this->assertJournalLines($entry, [
            ['5300', 500, 0],
            ['2120', 0, 500],
        ]);

        // Second post for the same month is rejected with an error flash.
        $this->actingAs($user)
            ->post(route('reports.profit-loss.pph'), ['month' => '2026-10'])
            ->assertSessionHas('error');
        $this->assertSame(1, JournalEntry::where('source_type', 'pph_period')->count());
    }
}
