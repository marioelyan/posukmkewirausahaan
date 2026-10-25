<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 1 slice 3: jurnal pelunasan piutang (spec §3.3).
 *
 * Dr 1100 Kas (method cash) atau 1110 Bank (media lain) / Cr 1120
 * Piutang Usaha — kas masuk saat pelanggan membayar, piutang berkurang.
 * (Slice 4 correction: slice 3 posted this backwards, Dr 1120 / Cr 1100,
 * which the backfill cash proof exposed. Spec §3.3 is Dr Kas/Bank.)
 * Hook hidup di ReceivableController::pay — satu-satunya
 * jalur pembuatan ReceivablePayment (tidak ada jalur API) — sehingga
 * tes HTTP membuktikan cakupan tepat sekali per pembayaran.
 */
class ReceivablePaymentJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class, AccountSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function receivable(int $total = 100000, int $paid = 0): Receivable
    {
        $customer = Customer::create([
            'name' => 'Customer Test',
            'no_telp' => '08123456789',
            'address' => 'Jalan Test',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $total,
            'payment_method' => 'pay_later',
            'payment_status' => 'unpaid',
        ]);

        return Receivable::create([
            'customer_id' => $customer->id,
            'transaction_id' => $transaction->id,
            'invoice' => $transaction->invoice,
            'total' => $total,
            'paid' => $paid,
            'due_date' => now()->addDays(7),
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

    public function test_cash_settlement_posts_journal_via_controller(): void
    {
        $receivable = $this->receivable();

        $response = $this->actingAs($this->admin)->post(route('receivables.pay', $receivable), [
            'amount' => 40000,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ]);

        $response->assertSessionDoesntHaveErrors();

        $entry = JournalEntry::where('source_type', 'ReceivablePayment')->firstOrFail();
        $this->assertSame(1, JournalEntry::where('source_type', 'ReceivablePayment')->count());
        $this->assertJournalLines($entry, [
            ['1100', 40000, 0],
            ['1120', 0, 40000],
        ]);

        $this->assertDatabaseHas('receivables', [
            'id' => $receivable->id,
            'paid' => 40000,
            'status' => 'partial',
        ]);
    }

    public function test_non_cash_settlement_credits_bank(): void
    {
        $receivable = $this->receivable();

        $this->actingAs($this->admin)->post(route('receivables.pay', $receivable), [
            'amount' => 60000,
            'paid_at' => now()->toDateString(),
            'method' => 'transfer',
        ])->assertSessionDoesntHaveErrors();

        $entry = JournalEntry::where('source_type', 'ReceivablePayment')->firstOrFail();
        $this->assertJournalLines($entry, [
            ['1110', 60000, 0],
            ['1120', 0, 60000],
        ]);
    }

    public function test_repost_is_idempotent(): void
    {
        $receivable = $this->receivable();

        $payment = ReceivablePayment::create([
            'receivable_id' => $receivable->id,
            'paid_at' => now()->toDateString(),
            'amount' => 25000,
            'method' => 'cash',
            'user_id' => $this->admin->id,
        ]);

        $service = app(JournalService::class);
        $first = $service->postReceivablePaymentJournal($payment, null);
        $second = $service->postReceivablePaymentJournal($payment, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'ReceivablePayment')->count());
    }
}
