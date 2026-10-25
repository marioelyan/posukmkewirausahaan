<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceiving;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\SupplierReturn;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fase 1 slice 1 + 2: posting jurnal (spec §3.1, §3.2, §3.7–§3.9, §4).
 *
 * Rules enforced here, and nowhere else:
 * - Balanced only: sum(debit) must equal sum(credit) and be positive,
 *   otherwise the entry is rejected before anything is saved.
 * - One side per line: each line fills exactly one of debit / credit.
 * - Idempotent per source document: (source_type, source_id) maps to at
 *   most one entry; a repeat post returns the existing entry.
 * - Immutable: no update or delete path exists. Corrections go through
 *   reverse(), which creates a new entry with swapped sides.
 * - Money is integer Rupiah; HPP always uses frozen checkout snapshots,
 *   never current master buy_price.
 * - One economic event, one journal: a drawer beban is journaled once via
 *   its Expense row, never again via its cash movement; a drawer debt
 *   payment is journaled once via its movement, never again via the
 *   PayablePayment mirror row it creates.
 */
class JournalService
{
    public const SOURCE_TRANSACTION = 'Transaction';

    public const SOURCE_EXPENSE = 'Expense';

    public const SOURCE_MOVEMENT = 'ShiftCashMovement';

    public const SOURCE_PAYABLE_PAYMENT = 'PayablePayment';

    public const SOURCE_SHIFT = 'CashierShift';

    public const SOURCE_RECEIVABLE_PAYMENT = 'ReceivablePayment';

    public const SOURCE_SALES_RETURN = 'SalesReturn';

    public const SOURCE_GOODS_RECEIVING = 'GoodsReceiving';

    public const SOURCE_SUPPLIER_RETURN = 'SupplierReturn';

    // PPh period entries (spec §3.10): keyed by business + month, not by a
    // transaction document. The spec names the accrual source 'pph_period';
    // the payment uses the parallel 'pph_payment'.
    public const SOURCE_PPH_PERIOD = 'pph_period';

    public const SOURCE_PPH_PAYMENT = 'pph_payment';

    public const SOURCE_REVERSAL = 'JournalReversal';

    /**
     * Spec §2.2: the 8 seeded expense categories map 1:1 to beban accounts.
     * Name wins over account_code: seeded rows carry a null account_code,
     * and a stray account_code must never shadow the spec mapping.
     */
    public const EXPENSE_CATEGORY_ACCOUNTS = [
        'Perlengkapan' => '5200',
        'Sewa Tempat' => '5210',
        'Gaji & Upah' => '5220',
        'Listrik & Air' => '5230',
        'Transport & Pengiriman' => '5240',
        'Pemasaran' => '5250',
        'Peralatan Kecil' => '5260',
        'Lainnya' => '5270',
    ];

    /**
     * Post a balanced entry. Idempotent per (source_type, source_id): a
     * repeat post returns the existing entry and creates nothing.
     *
     * @param  array<int, array{code: string, debit?: int, credit?: int, note?: string}>  $lines
     */
    public function post(
        string $sourceType,
        int $sourceId,
        ?int $businessId,
        string $date,
        ?string $description,
        array $lines,
        ?int $createdBy = null,
    ): JournalEntry {
        return DB::transaction(function () use ($sourceType, $sourceId, $businessId, $date, $description, $lines, $createdBy) {
            $existing = JournalEntry::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }

            return $this->createEntry($sourceType, $sourceId, $businessId, $date, $description, $lines, null, $createdBy);
        });
    }

    /**
     * Reverse an entry: a new balanced entry with every side swapped.
     *
     * One direct reversal per entry; to correct a reversal, reverse the
     * reversal (its own id keeps the idempotency key unique).
     */
    public function reverse(JournalEntry $entry, ?string $description = null, ?int $createdBy = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $description, $createdBy) {
            if ($entry->reversal()->exists()) {
                throw new InvalidArgumentException(
                    "Journal entry {$entry->id} already has a reversal; reverse the reversal instead."
                );
            }

            $lines = $entry->lines()->with('account')->get()->map(fn ($line) => [
                'code' => $line->account->code,
                'debit' => (int) $line->credit,
                'credit' => (int) $line->debit,
                'note' => "Reversal of entry {$entry->id}",
            ])->all();

            return $this->createEntry(
                self::SOURCE_REVERSAL,
                $entry->id,
                $entry->business_id,
                now()->toDateString(),
                $description ?? "Reversal of entry {$entry->id}",
                $lines,
                $entry->id,
                $createdBy,
            );
        });
    }

    /**
     * Build and post the sales journal for a checkout transaction
     * (spec §3.1 cash, §3.2 credit).
     *
     * Returns null for payment types slice 1 does not journal: bank
     * transfer, QRIS, split tenders, and pending gateway payments. Those
     * need a follow-up slice (tender-to-bank-account mapping).
     */
    public function postSalesJournal(Transaction $transaction, ?int $businessId): ?JournalEntry
    {
        $isCredit = $transaction->payment_method === 'pay_later';
        $isCash = $transaction->payment_status === 'paid' && $transaction->payment_method === 'cash';

        if (! $isCredit && ! $isCash) {
            return null;
        }

        $transaction->loadMissing('details');

        $ownedHpp = 0;
        $consignmentHpp = 0;

        foreach ($transaction->details as $detail) {
            // Frozen snapshot values (spec §3.1): the buy_price recorded at
            // checkout, never the product's current master buy_price.
            // Note: for composite products this differs from Profit, which
            // uses component costs; snapshotting component costs is a
            // follow-up (see slice report).
            $hpp = (int) round((int) $detail->buy_price * (int) $detail->qty * (float) ($detail->conversion_factor ?? 1));

            if ($detail->is_consignment) {
                $consignmentHpp += $hpp;
            } else {
                $ownedHpp += $hpp;
            }
        }

        $grandTotal = (int) $transaction->grand_total;

        $lines = [];

        if ($grandTotal > 0) {
            $lines[] = ['code' => $isCredit ? '1120' : '1100', 'debit' => $grandTotal];
            $lines[] = ['code' => '4100', 'credit' => $grandTotal];
        }

        if ($ownedHpp > 0) {
            $lines[] = ['code' => '5100', 'debit' => $ownedHpp];
            $lines[] = ['code' => '1140', 'credit' => $ownedHpp];
        }

        if ($consignmentHpp > 0) {
            $lines[] = ['code' => '5100', 'debit' => $consignmentHpp];
            $lines[] = ['code' => '2110', 'credit' => $consignmentHpp];
        }

        if ($lines === []) {
            return null;
        }

        return $this->post(
            self::SOURCE_TRANSACTION,
            $transaction->id,
            $businessId,
            $transaction->created_at?->toDateString() ?? now()->toDateString(),
            'Penjualan '.$transaction->invoice,
            $lines,
            $transaction->cashier_id,
        );
    }

    /**
     * Resolve the beban account code for an expense category (spec §2.2).
     *
     * Seeded names map by name; a custom category maps through its
     * account_code when that code is a beban account (5200–5270). Anything
     * else has no mapping and cannot be used in an Expense — callers turn
     * this into a validation error before anything is saved.
     */
    public function expenseAccountCode(ExpenseCategory $category): string
    {
        if (isset(self::EXPENSE_CATEGORY_ACCOUNTS[$category->name])) {
            return self::EXPENSE_CATEGORY_ACCOUNTS[$category->name];
        }

        $code = (string) ($category->account_code ?? '');

        // Only the expense-category range: 5280 (cash differences) and
        // 5100 (HPP) / 5300 (tax) are never expense-category accounts.
        if ($code !== '' && isset(Account::CHART[$code]) && $code >= '5200' && $code <= '5270') {
            return $code;
        }

        throw new InvalidArgumentException(
            "Expense category '{$category->name}' has no account mapping; map it to a beban account (5200–5270) before use."
        );
    }

    /**
     * Post the expense journal (spec §3.7).
     *
     * Debit 52xx by category mapping; credit follows the Expense source:
     * drawer and other_cash hit 1100 Kas, bank hits 1110 Bank.
     */
    public function postExpenseJournal(Expense $expense, ?int $businessId): JournalEntry
    {
        $expense->loadMissing('category');

        $debitCode = $this->expenseAccountCode($expense->category);
        $creditCode = $expense->source === Expense::SOURCE_BANK ? '1110' : '1100';
        $amount = (int) $expense->amount;

        return $this->post(
            self::SOURCE_EXPENSE,
            $expense->id,
            $businessId ?? $expense->business_id,
            $expense->date?->toDateString() ?? now()->toDateString(),
            'Beban '.$expense->category->name,
            [
                ['code' => $debitCode, 'debit' => $amount],
                ['code' => $creditCode, 'credit' => $amount],
            ],
            $expense->user_id,
        );
    }

    /**
     * Post the shift cash-movement journal (spec §3.8).
     *
     * Returns null when the movement has no journal: kind `beban` (its
     * Expense row carries the journal, never the movement itself), a null
     * legacy kind, a setoran without a destination (old rows are left
     * unjournaled; the backfill slice decides them), or a pelunasan whose
     * payable is gone.
     */
    public function postMovementJournal(ShiftCashMovement $movement, ?int $businessId): ?JournalEntry
    {
        $amount = (int) $movement->amount;

        if ($amount <= 0 || $movement->kind === null) {
            return null;
        }

        $lines = match ([$movement->type, $movement->kind]) {
            [ShiftCashMovement::TYPE_OUT, 'beban'] => null,
            [ShiftCashMovement::TYPE_OUT, 'setoran'] => $movement->setoran_destination === null ? null : [
                ['code' => $movement->setoran_destination === ShiftCashMovement::SETORAN_DESTINATION_OWNER ? '3110' : '1110', 'debit' => $amount],
                ['code' => '1100', 'credit' => $amount],
            ],
            [ShiftCashMovement::TYPE_IN, 'setoran_masuk'] => [
                ['code' => '1100', 'debit' => $amount],
                ['code' => '1110', 'credit' => $amount],
            ],
            [ShiftCashMovement::TYPE_OUT, 'talangan'] => [
                ['code' => '1130', 'debit' => $amount],
                ['code' => '1100', 'credit' => $amount],
            ],
            // Locked Q1: talangan_masuk repays an advance.
            [ShiftCashMovement::TYPE_IN, 'talangan_masuk'] => [
                ['code' => '1100', 'debit' => $amount],
                ['code' => '1130', 'credit' => $amount],
            ],
            [ShiftCashMovement::TYPE_OUT, 'pelunasan_hutang'] => $this->payablePaymentLines($movement->payable, $amount, '1100'),
            [ShiftCashMovement::TYPE_IN, 'lainnya'] => [
                ['code' => '1100', 'debit' => $amount],
                ['code' => '4200', 'credit' => $amount],
            ],
            [ShiftCashMovement::TYPE_OUT, 'lainnya'] => [
                ['code' => '5270', 'debit' => $amount],
                ['code' => '1100', 'credit' => $amount],
            ],
            [ShiftCashMovement::TYPE_IN, 'modal'] => [
                ['code' => '1100', 'debit' => $amount],
                ['code' => '3100', 'credit' => $amount],
            ],
            default => null,
        };

        if ($lines === null) {
            return null;
        }

        $movement->loadMissing('shift');

        return $this->post(
            self::SOURCE_MOVEMENT,
            $movement->id,
            $businessId ?? $movement->shift?->business_id,
            $movement->created_at?->toDateString() ?? now()->toDateString(),
            'Pergerakan kas '.($movement->kind ?? 'lainnya'),
            $lines,
            $movement->user_id,
        );
    }

    /**
     * Post the bank-payment journal for a debt paid through pay()
     * (spec §3.8: bank payments credit 1110).
     *
     * Drawer payments are NOT journaled here: their movement already
     * carries the journal (see postMovementJournal), and journaling the
     * PayablePayment mirror row as well would double-count the payment.
     */
    public function postPayablePaymentJournal(PayablePayment $payment, ?int $businessId): ?JournalEntry
    {
        $amount = (int) $payment->amount;

        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing('payable');

        $lines = $this->payablePaymentLines(
            $payment->payable,
            $amount,
            $payment->method === 'cash' ? '1100' : '1110',
        );

        if ($lines === null) {
            return null;
        }

        return $this->post(
            self::SOURCE_PAYABLE_PAYMENT,
            $payment->id,
            $businessId ?? $payment->payable?->business_id,
            $payment->paid_at?->toDateString() ?? now()->toDateString(),
            'Pembayaran hutang '.($payment->payable?->document_number ?? ''),
            $lines,
            $payment->user_id,
        );
    }

    /**
     * Fase 1 slice 4: drawer-mirror detection, shared by the backfill
     * command and the cash proof. A drawer debt payment creates BOTH a
     * shift movement (which carries the journal) and a PayablePayment
     * mirror row (which must never be journaled again). A mirror row is
     * recognised by a pelunasan_hutang movement for the same payable,
     * amount and user, created within minutes of the payment — both rows
     * are written in the same request. Bank pay() payments have no such
     * movement and are always journaled. The drawer note marker
     * '(dari laci shift #…)' is a second, independent signal: only the
     * drawer flow ever writes it.
     */
    public function isDrawerMirrorPayment(PayablePayment $payment): bool
    {
        if (
            $payment->method === 'cash'
            && $payment->bank_account_id === null
            && is_string($payment->note)
            && str_contains($payment->note, '(dari laci shift #')
        ) {
            return true;
        }

        if ($payment->created_at === null) {
            return false;
        }

        return ShiftCashMovement::query()
            ->where('payable_id', $payment->payable_id)
            ->where('kind', 'pelunasan_hutang')
            ->where('amount', (int) $payment->amount)
            ->where('user_id', $payment->user_id)
            ->whereBetween('created_at', [
                $payment->created_at->copy()->subMinutes(10),
                $payment->created_at->copy()->addMinutes(10),
            ])
            ->exists();
    }

    /**
     * Resolve the debit side of a debt payment: a payable born from a sale
     * (transaction_id set) is a consignment payable (2110); anything else
     * is a supplier payable (2100). Returns null when the payable is gone.
     */
    private function payablePaymentLines(?Payable $payable, int $amount, string $creditCode): ?array
    {
        if ($payable === null) {
            return null;
        }

        return [
            ['code' => $payable->transaction_id !== null ? '2110' : '2100', 'debit' => $amount],
            ['code' => $creditCode, 'credit' => $amount],
        ];
    }

    /**
     * Post the cash-difference journal at shift close (spec §3.9).
     *
     * Short: Dr 5280 / Cr 1100. Over: Dr 1100 / Cr 4200. Zero: no journal.
     * Keyed on the shift itself: a shift closes exactly once, so the
     * idempotency key (CashierShift, shift id) can only ever hold one entry.
     * Note: spec §4's source_type list does not name CashierShift; the shift
     * is nevertheless the source document of its own closing difference.
     */
    public function postCashDifferenceJournal(CashierShift $shift, ?int $businessId): ?JournalEntry
    {
        $difference = (int) ($shift->cash_difference ?? 0);

        if ($difference === 0) {
            return null;
        }

        $absolute = abs($difference);

        $lines = $difference < 0
            ? [
                ['code' => '5280', 'debit' => $absolute],
                ['code' => '1100', 'credit' => $absolute],
            ]
            : [
                ['code' => '1100', 'debit' => $absolute],
                ['code' => '4200', 'credit' => $absolute],
            ];

        return $this->post(
            self::SOURCE_SHIFT,
            $shift->id,
            $businessId ?? $shift->business_id,
            $shift->closed_at?->toDateString() ?? now()->toDateString(),
            'Selisih kas shift #'.$shift->id,
            $lines,
            $shift->closed_by,
        );
    }

    /**
     * Post the receivable-settlement journal (spec §3.3).
     *
     * Cash comes in when a customer pays, so the payment media is debited
     * (1100 Kas for method 'cash', 1110 Bank otherwise) and 1120 Piutang
     * Usaha is credited to clear the receivable created at sale time.
     * (Slice 3 originally posted this backwards — Dr 1120 / Cr 1100 —
     * which the slice 4 backfill cash proof exposed: journal kas was
     * understated by twice the settled amount. Fixed here.)
     */
    public function postReceivablePaymentJournal(ReceivablePayment $payment, ?int $businessId): ?JournalEntry
    {
        $amount = (int) $payment->amount;

        if ($amount <= 0) {
            return null;
        }

        return $this->post(
            self::SOURCE_RECEIVABLE_PAYMENT,
            $payment->id,
            $businessId,
            $payment->paid_at?->toDateString() ?? now()->toDateString(),
            'Pelunasan piutang',
            [
                ['code' => $payment->method === 'cash' ? '1100' : '1110', 'debit' => $amount],
                ['code' => '1120', 'credit' => $amount],
            ],
            $payment->user_id,
        );
    }

    /**
     * Post the sales-return journal (spec §3.4).
     *
     * One entry per return, with merged same-account legs:
     * - Dr 4110 Retur Penjualan for the full return value that is settled
     *   in cash or against the receivable (refund + receivable reduction).
     * - Cr 1100 Kas for the cash-refund portion, Cr 1120 Piutang Usaha for
     *   the portion that only reduces the receivable.
     * - Dr 1140 Persediaan / Cr 5100 HPP for restocked owned goods, valued
     *   at the frozen checkout snapshot (buy_price x qty x conversion).
     * - Dr 2110 Hutang Penitip / Cr 5100 HPP for the consignment payable
     *   reduction actually applied (floored at the paid amount, same rule
     *   as the K2 payable reduction); the Persediaan leg is replaced, per
     *   spec, even when the goods were physically restocked.
     *
     * Store-credit settlement has no account in the phase 1 chart, so its
     * revenue-reversal leg is deliberately NOT posted here: the credited
     * portion is a liability the spec does not model yet (see slice report).
     * The restock legs still post; the entry itself always balances.
     */
    public function postSalesReturnJournal(
        SalesReturn $salesReturn,
        ?int $businessId,
        int $refundAmount,
        int $receivableReduction,
        int $ownedRestockValue,
        int $consignmentReduction,
    ): ?JournalEntry {
        $returnDebit = $refundAmount + $receivableReduction;
        $restockCredit = $ownedRestockValue + $consignmentReduction;

        $lines = [];

        if ($returnDebit > 0) {
            $lines[] = ['code' => '4110', 'debit' => $returnDebit];
        }

        if ($refundAmount > 0) {
            $lines[] = ['code' => '1100', 'credit' => $refundAmount];
        }

        if ($receivableReduction > 0) {
            $lines[] = ['code' => '1120', 'credit' => $receivableReduction];
        }

        if ($ownedRestockValue > 0) {
            $lines[] = ['code' => '1140', 'debit' => $ownedRestockValue];
        }

        if ($consignmentReduction > 0) {
            $lines[] = ['code' => '2110', 'debit' => $consignmentReduction];
        }

        if ($restockCredit > 0) {
            $lines[] = ['code' => '5100', 'credit' => $restockCredit];
        }

        if ($lines === []) {
            return null;
        }

        return $this->post(
            self::SOURCE_SALES_RETURN,
            $salesReturn->id,
            $businessId,
            $salesReturn->completed_at?->toDateString() ?? now()->toDateString(),
            'Retur penjualan '.$salesReturn->code,
            $lines,
            $salesReturn->cashier_id,
        );
    }

    /**
     * Post the goods-receiving journal (spec §3.5).
     *
     * Debit 1140 Persediaan / credit 2100 Hutang Usaha for the value
     * received in THIS receiving. One journal per receiving, so partial
     * receivings journal their own value each. Returns null for a zero
     * value; callers skip the journal entirely when no supplier exists
     * (no Hutang Usaha counterparty).
     */
    public function postGoodsReceivingJournal(GoodsReceiving $receiving, ?int $businessId, int $value): ?JournalEntry
    {
        if ($value <= 0) {
            return null;
        }

        return $this->post(
            self::SOURCE_GOODS_RECEIVING,
            $receiving->id,
            $businessId,
            $receiving->received_at?->toDateString() ?? now()->toDateString(),
            'Penerimaan barang '.$receiving->document_number,
            [
                ['code' => '1140', 'debit' => $value],
                ['code' => '2100', 'credit' => $value],
            ],
            $receiving->received_by,
        );
    }

    /**
     * Post the supplier-return journal (spec §3.11).
     *
     * The caller splits the return value into the payable reduction (the
     * unpaid portion, Dr 2100) and the already-paid portion (Dr 1130
     * Piutang Lainnya, a claim on the supplier); both credit 1140
     * Persediaan. The split must add up to the return value, otherwise
     * createEntry rejects the entry as unbalanced.
     */
    public function postSupplierReturnJournal(
        SupplierReturn $return,
        ?int $businessId,
        int $returnAmount,
        int $payableReduction,
        int $paidPortion,
    ): ?JournalEntry {
        if ($returnAmount <= 0) {
            return null;
        }

        $lines = [];

        if ($payableReduction > 0) {
            $lines[] = ['code' => '2100', 'debit' => $payableReduction];
        }

        if ($paidPortion > 0) {
            $lines[] = ['code' => '1130', 'debit' => $paidPortion];
        }

        $lines[] = ['code' => '1140', 'credit' => $returnAmount];

        return $this->post(
            self::SOURCE_SUPPLIER_RETURN,
            $return->id,
            $businessId,
            $return->returned_at?->toDateString() ?? now()->toDateString(),
            'Retur supplier '.$return->document_number,
            $lines,
            $return->created_by,
        );
    }

    /**
     * Post the monthly PPh accrual (spec §3.10): Dr 5300 Beban Pajak /
     * Cr 2120 Hutang Pajak.
     *
     * One live entry per (business, month): a second accrual for the same
     * month is rejected unless the first entry was reversed. The unique
     * index on (source_type, source_id) is global, not per business, so
     * the source id encodes business + month (business * 1_000_000 +
     * YYYYMM) and increments past any reversed entries for the month.
     * Returns null for a zero tax (nothing to post).
     */
    public function postPphAccrual(
        ?int $businessId,
        string $period,
        int $omset,
        int $taxAmount,
        ?int $userId,
    ): ?JournalEntry {
        if ($taxAmount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($businessId, $period, $omset, $taxAmount, $userId) {
            $this->ensureNoLivePphEntry($businessId, self::SOURCE_PPH_PERIOD, $period);

            return $this->createEntry(
                self::SOURCE_PPH_PERIOD,
                $this->pphSourceId($businessId, $period, self::SOURCE_PPH_PERIOD),
                $businessId,
                Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString(),
                'PPh Final 0,5% '.$period.' (omset Rp'.number_format($omset, 0, ',', '.').')',
                [
                    ['code' => '5300', 'debit' => $taxAmount],
                    ['code' => '2120', 'credit' => $taxAmount],
                ],
                null,
                $userId,
                $period,
            );
        });
    }

    /**
     * Post the PPh payment journal (spec §3.10): Dr 2120 Hutang Pajak /
     * Cr 1100 Kas (method 'cash') or 1110 Bank (anything else).
     *
     * One live payment per (business, month), same uniqueness rule as the
     * accrual. The amount must equal the live accrual's tax amount: partial
     * tax payments are not modelled in this slice.
     */
    public function postPphPayment(
        ?int $businessId,
        string $period,
        int $amount,
        string $method,
        ?int $userId,
    ): JournalEntry {
        return DB::transaction(function () use ($businessId, $period, $amount, $method, $userId) {
            $this->ensureNoLivePphEntry($businessId, self::SOURCE_PPH_PAYMENT, $period);

            return $this->createEntry(
                self::SOURCE_PPH_PAYMENT,
                $this->pphSourceId($businessId, $period, self::SOURCE_PPH_PAYMENT),
                $businessId,
                now()->toDateString(),
                'Pembayaran PPh Final '.$period,
                [
                    ['code' => '2120', 'debit' => $amount],
                    ['code' => $method === 'cash' ? '1100' : '1110', 'credit' => $amount],
                ],
                null,
                $userId,
                $period,
            );
        });
    }

    /**
     * The live (non-reversed) PPh entry for a (business, source, month),
     * or null. Used by PphService to find the accrual a payment settles.
     */
    public function livePphEntry(?int $businessId, string $sourceType, string $period): ?JournalEntry
    {
        return $this->pphEntries($businessId, $sourceType, $period)
            ->orderByDesc('id')
            ->get()
            ->first(fn (JournalEntry $entry) => ! $entry->reversal()->exists());
    }

    /**
     * Reject a second live PPh entry for the same (business, source,
     * month). Entries whose reversal exists do not block a re-post.
     */
    private function ensureNoLivePphEntry(?int $businessId, string $sourceType, string $period): void
    {
        $live = $this->pphEntries($businessId, $sourceType, $period)
            ->get()
            ->first(fn (JournalEntry $entry) => ! $entry->reversal()->exists());

        if ($live !== null) {
            throw new InvalidArgumentException(
                "PPh {$period} already has a posted {$sourceType} entry (#{$live->id}); reverse it first to re-post."
            );
        }
    }

    /**
     * All PPh entries for a (business, source, month), oldest first.
     */
    private function pphEntries(?int $businessId, string $sourceType, string $period)
    {
        return JournalEntry::query()
            ->where(function ($query) use ($businessId) {
                if ($businessId === null) {
                    $query->whereNull('business_id');
                } else {
                    $query->where('business_id', $businessId);
                }
            })
            ->where('source_type', $sourceType)
            ->where('period', $period)
            ->orderBy('id');
    }

    /**
     * Source id for a PPh entry: business * 1_000_000 + YYYYMM, plus the
     * count of existing entries for the month so a re-post after a
     * reversal never collides with the retained original row.
     */
    private function pphSourceId(?int $businessId, string $period, string $sourceType): int
    {
        $base = ($businessId ?? 0) * 1000000 + (int) str_replace('-', '', $period);

        return $base + $this->pphEntries($businessId, $sourceType, $period)->count();
    }

    /**
     * Validate and create an entry with its lines. Shared by post() and
     * reverse(); reverse() bypasses the idempotency lookup by design.
     */
    private function createEntry(
        string $sourceType,
        int $sourceId,
        ?int $businessId,
        string $date,
        ?string $description,
        array $lines,
        ?int $reversesEntryId,
        ?int $createdBy,
        ?string $period = null,
    ): JournalEntry {
        $resolved = $this->resolveLines($businessId, $lines);

        $totalDebit = array_sum(array_column($resolved, 'debit'));
        $totalCredit = array_sum(array_column($resolved, 'credit'));

        if ($totalDebit <= 0 || $totalDebit !== $totalCredit) {
            throw new InvalidArgumentException(
                "Unbalanced journal entry for {$sourceType} #{$sourceId}: debit {$totalDebit} != credit {$totalCredit}."
            );
        }

        $entry = JournalEntry::create([
            'business_id' => $businessId,
            'date' => $date,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'period' => $period,
            'reverses_entry_id' => $reversesEntryId,
            'description' => $description,
            'created_by' => $createdBy,
        ]);

        foreach ($resolved as $line) {
            $entry->lines()->create($line);
        }

        return $entry->fresh('lines');
    }

    /**
     * Resolve account codes to ids and validate the one-side-per-line rule.
     *
     * Accounts are ensured on demand: checkout must never fail just because
     * the seeder has not run for a business. The seeder remains the
     * canonical, auditable path; this is only a safety net.
     *
     * @return array<int, array{account_id: int, debit: int, credit: int, note: ?string}>
     */
    private function resolveLines(?int $businessId, array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Journal entry needs at least one line.');
        }

        $resolved = [];

        foreach ($lines as $line) {
            $code = (string) ($line['code'] ?? '');
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if (! isset(Account::CHART[$code])) {
                throw new InvalidArgumentException("Unknown account code '{$code}'.");
            }

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException("Negative journal amount for account {$code}.");
            }

            if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
                throw new InvalidArgumentException(
                    "Journal line for account {$code} must fill exactly one side."
                );
            }

            [$name, $category] = Account::CHART[$code];

            $account = Account::firstOrCreate(
                ['business_id' => $businessId, 'code' => $code],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );

            $resolved[] = [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'note' => $line['note'] ?? null,
            ];
        }

        return $resolved;
    }
}
