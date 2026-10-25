<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\GoodsReceiving;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\PurchaseOrderItem;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\ShiftCashMovement;
use App\Models\SupplierReturn;
use App\Models\Transaction;
use InvalidArgumentException;

/**
 * Fase 1 slice 4 (spec §6 Q2): one-time backfill of journals for all
 * historical source documents, using exactly the same JournalService
 * mappings as the live hooks in slices 1–3.
 *
 * Safety rules:
 * - Idempotent: a source that already has a journal entry is never
 *   posted again, so re-runs (including --force) create nothing new.
 * - One run per scope is recorded in settings; a second run without
 *   --force is refused. --force still skips already-journaled sources.
 * - --dry-run posts nothing and writes no marker; it reports what WOULD
 *   be posted, what would be skipped, and what would fail.
 * - Every skipped or failed source is counted with a reason. Nothing is
 *   silently dropped: a document the backfill cannot journal exactly is
 *   left unjournaled and reported, never force-posted with a guess.
 * - Historical returns are reconstructed chronologically per receivable /
 *   payable, and the simulation is cross-checked against the currently
 *   stored totals. A mismatch means the history cannot be replayed
 *   exactly, so those returns are skipped and reported.
 */
class AccountingBackfillService
{
    public const MARKER_PREFIX = 'accounting_backfill_completed';

    public function __construct(
        private readonly JournalService $journals,
        private readonly FinancialOwnershipService $ownership,
        private readonly JournalReportService $reports,
    ) {}

    public function markerKey(?int $businessId): string
    {
        return self::MARKER_PREFIX.($businessId === null ? '_all' : '_business_'.$businessId);
    }

    public function alreadyCompleted(?int $businessId): bool
    {
        return Setting::query()
            ->where('key', $this->markerKey($businessId))
            ->whereNull('business_id')
            ->whereNull('outlet_id')
            ->exists();
    }

    public function markCompleted(?int $businessId): void
    {
        Setting::updateOrCreate(
            ['key' => $this->markerKey($businessId), 'business_id' => null, 'outlet_id' => null],
            ['value' => now()->toDateTimeString(), 'description' => 'Fase 1 slice 4: backfill jurnal selesai'],
        );
    }

    /**
     * Run the backfill and return the full report:
     * ['types' => [type => ['posted', 'already', 'skipped' => [reason => count], 'failed' => [['id', 'error']]]],
     *  'verification' => [...]].
     * In dry-run mode 'posted' counts sources that WOULD be posted.
     */
    public function run(?int $businessId, bool $dryRun): array
    {
        $report = [
            'types' => [
                'sales' => $this->backfillSales($businessId, $dryRun),
                'expenses' => $this->backfillExpenses($businessId, $dryRun),
                'movements' => $this->backfillMovements($businessId, $dryRun),
                'payable_payments' => $this->backfillPayablePayments($businessId, $dryRun),
                'receivable_payments' => $this->backfillReceivablePayments($businessId, $dryRun),
                'cash_differences' => $this->backfillCashDifferences($businessId, $dryRun),
                'receivings' => $this->backfillReceivings($businessId, $dryRun),
                'sales_returns' => $this->backfillSalesReturns($businessId, $dryRun),
                'supplier_returns' => $this->backfillSupplierReturns($businessId, $dryRun),
            ],
        ];

        $report['verification'] = $this->verify($businessId, $dryRun);

        return $report;
    }

    /**
     * Verification block: trial balance, lite reconciliation (installation
     * scope only — the lite report has no business scope), and cash proof.
     * In dry-run mode this describes the CURRENT state, before any new
     * journal is posted.
     */
    public function verify(?int $businessId, bool $dryRun): array
    {
        $verification = [
            'dry_run' => $dryRun,
            'trial_balance' => $this->reports->trialBalance($businessId),
        ];

        if ($businessId === null) {
            $verification['reconciliation'] = $this->reports->reconcileLite(null, null);
            $verification['cash_proof'] = $this->reports->cashProof();
        } else {
            $verification['reconciliation'] = null;
            $verification['cash_proof'] = null;
            $verification['note'] = 'Rekonsiliasi lite dan bukti kas hanya tersedia untuk seluruh instalasi (laporan lite tidak mengenal business).';
        }

        return $verification;
    }

    // ------------------------------------------------------------------
    // Per-type backfills
    // ------------------------------------------------------------------

    private function newTypeReport(): array
    {
        return ['posted' => 0, 'already' => 0, 'skipped' => [], 'failed' => []];
    }

    private function skip(array &$report, string $reason): void
    {
        $report['skipped'][$reason] = ($report['skipped'][$reason] ?? 0) + 1;
    }

    private function alreadyJournaled(string $sourceType, int $sourceId): bool
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    private function businessForTransaction(Transaction $transaction): ?int
    {
        if ($transaction->warehouse_id === null) {
            return null;
        }

        return $this->ownership->stampFromWarehouse((int) $transaction->warehouse_id)['business_id'];
    }

    private function backfillSales(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $transactions = Transaction::query()
            ->with('details')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            if ($businessId !== null && $this->businessForTransaction($transaction) !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_TRANSACTION, $transaction->id)) {
                $report['already']++;

                continue;
            }

            // Same null conditions as JournalService::postSalesJournal:
            // only cash-paid and pay_later sales are journaled; bank,
            // QRIS, split and pending sales need tender-to-bank mapping.
            $isCredit = $transaction->payment_method === 'pay_later';
            $isCash = $transaction->payment_status === 'paid' && $transaction->payment_method === 'cash';

            if (! $isCredit && ! $isCash) {
                $this->skip($report, 'method_not_journaled');

                continue;
            }

            $hpp = 0;

            foreach ($transaction->details as $detail) {
                $hpp += (int) round((int) $detail->buy_price * (int) $detail->qty * (float) ($detail->conversion_factor ?? 1));
            }

            if ((int) $transaction->grand_total <= 0 && $hpp <= 0) {
                $this->skip($report, 'zero_amount');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postSalesJournal($transaction, $this->businessForTransaction($transaction));

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    private function backfillExpenses(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $expenses = Expense::query()->with('category')->orderBy('date')->orderBy('id')->get();

        foreach ($expenses as $expense) {
            if ($businessId !== null && (int) $expense->business_id !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_EXPENSE, $expense->id)) {
                $report['already']++;

                continue;
            }

            if ((int) $expense->amount <= 0) {
                $report['failed'][] = ['id' => $expense->id, 'error' => 'Non-positive amount'];

                continue;
            }

            try {
                // Dry-run must surface the same failure the live post
                // would hit (unmapped category) without posting anything.
                $this->journals->expenseAccountCode($expense->category);
            } catch (InvalidArgumentException $e) {
                $report['failed'][] = ['id' => $expense->id, 'error' => $e->getMessage()];

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            try {
                $this->journals->postExpenseJournal($expense, $expense->business_id);
                $report['posted']++;
            } catch (InvalidArgumentException $e) {
                $report['failed'][] = ['id' => $expense->id, 'error' => $e->getMessage()];
            }
        }

        return $report;
    }

    private function backfillMovements(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $movements = ShiftCashMovement::query()->with('shift')->orderBy('created_at')->orderBy('id')->get();

        foreach ($movements as $movement) {
            $movementBusiness = $movement->shift?->business_id;

            if ($businessId !== null && (int) $movementBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_MOVEMENT, $movement->id)) {
                $report['already']++;

                continue;
            }

            // Same null conditions as JournalService::postMovementJournal.
            if ((int) $movement->amount <= 0) {
                $this->skip($report, 'zero_amount');
            } elseif ($movement->kind === null) {
                $this->skip($report, 'null_kind');
            } elseif ($movement->type === ShiftCashMovement::TYPE_OUT && $movement->kind === 'beban') {
                $this->skip($report, 'beban_via_expense');
            } elseif ($movement->type === ShiftCashMovement::TYPE_OUT && $movement->kind === 'setoran' && $movement->setoran_destination === null) {
                $this->skip($report, 'setoran_no_destination');
            } elseif ($movement->type === ShiftCashMovement::TYPE_OUT && $movement->kind === 'pelunasan_hutang' && $movement->payable_id !== null && Payable::whereKey($movement->payable_id)->doesntExist()) {
                $this->skip($report, 'missing_payable');
            } elseif ($dryRun) {
                $report['posted']++;
            } else {
                $entry = $this->journals->postMovementJournal($movement, $movementBusiness);

                if ($entry === null) {
                    $this->skip($report, 'post_returned_null');
                } else {
                    $report['posted']++;
                }
            }
        }

        return $report;
    }

    private function backfillPayablePayments(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $payments = PayablePayment::query()->with('payable')->orderBy('created_at')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $paymentBusiness = $payment->payable?->business_id;

            if ($businessId !== null && (int) $paymentBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_PAYABLE_PAYMENT, $payment->id)) {
                $report['already']++;

                continue;
            }

            if ($this->journals->isDrawerMirrorPayment($payment)) {
                $this->skip($report, 'drawer_mirror');

                continue;
            }

            if ($payment->payable === null) {
                $this->skip($report, 'missing_payable');

                continue;
            }

            if ((int) $payment->amount <= 0) {
                $this->skip($report, 'zero_amount');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postPayablePaymentJournal($payment, $paymentBusiness);

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    private function backfillReceivablePayments(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $payments = ReceivablePayment::query()->with('receivable')->orderBy('created_at')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $paymentBusiness = $payment->receivable?->business_id;

            if ($businessId !== null && (int) $paymentBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_RECEIVABLE_PAYMENT, $payment->id)) {
                $report['already']++;

                continue;
            }

            if ($payment->receivable === null) {
                $this->skip($report, 'missing_receivable');

                continue;
            }

            if ((int) $payment->amount <= 0) {
                $this->skip($report, 'zero_amount');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postReceivablePaymentJournal($payment, $paymentBusiness);

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    private function backfillCashDifferences(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $shifts = CashierShift::query()
            ->whereIn('status', [CashierShift::STATUS_CLOSED, CashierShift::STATUS_FORCE_CLOSED])
            ->whereNotNull('closed_at')
            ->orderBy('closed_at')
            ->orderBy('id')
            ->get();

        foreach ($shifts as $shift) {
            if ($businessId !== null && (int) $shift->business_id !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_SHIFT, $shift->id)) {
                $report['already']++;

                continue;
            }

            if ((int) ($shift->cash_difference ?? 0) === 0) {
                $this->skip($report, 'zero_difference');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postCashDifferenceJournal($shift, $shift->business_id);

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    private function receivingValue(GoodsReceiving $receiving): int
    {
        $value = 0;

        foreach ($receiving->items as $item) {
            $unitPrice = $item->purchase_order_item_id !== null
                ? (int) (PurchaseOrderItem::whereKey($item->purchase_order_item_id)->value('unit_price') ?? 0)
                : 0;

            $value += (int) $item->qty_received * $unitPrice;
        }

        return $value;
    }

    private function backfillReceivings(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $receivings = GoodsReceiving::query()->with(['items', 'purchaseOrder'])->orderBy('received_at')->orderBy('id')->get();

        foreach ($receivings as $receiving) {
            $receivingBusiness = $receiving->business_id ?? $receiving->purchaseOrder?->business_id;

            if ($businessId !== null && (int) $receivingBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_GOODS_RECEIVING, $receiving->id)) {
                $report['already']++;

                continue;
            }

            if ($receiving->supplier_id === null) {
                $this->skip($report, 'no_supplier');

                continue;
            }

            $value = $this->receivingValue($receiving);

            if ($value <= 0) {
                $this->skip($report, 'zero_value');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postGoodsReceivingJournal($receiving, $receivingBusiness, $value);

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // Return reconstruction (chronological replay + cross-check)
    // ------------------------------------------------------------------

    /**
     * Replay completed sales returns to recover the exact inputs the live
     * slice-3 hook used: refund and credited amounts are stored on the
     * row; owned restock value is recomputed from items and frozen
     * snapshots; receivable and consignment-payable reductions are
     * replayed chronologically and cross-checked against the currently
     * stored receivable / payable totals. A return whose group fails the
     * cross-check is marked invalid and skipped, never guessed.
     *
     * Returns: [returnId => ['refund', 'receivable_reduction',
     * 'owned_restock', 'consignment_reduction', 'valid']].
     */
    private function reconstructSalesReturns(): array
    {
        $returns = SalesReturn::query()
            ->with(['items.transactionDetail', 'items.product', 'transaction.details', 'transaction.receivable'])
            ->where('status', 'completed')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($returns as $salesReturn) {
            $result[$salesReturn->id] = [
                'refund' => (int) $salesReturn->refund_amount,
                'receivable_reduction' => 0,
                'owned_restock' => 0,
                'consignment_reduction' => 0,
                'valid' => true,
            ];

            foreach ($salesReturn->items as $item) {
                $detail = $item->transactionDetail;

                if (! $detail || ! $item->restock_to_inventory || $detail->is_consignment) {
                    continue;
                }

                $snapshotBuy = (int) ($detail->buy_price ?? $item->product?->buy_price ?? 0);
                $result[$salesReturn->id]['owned_restock'] += (int) round(
                    (int) $item->qty_return * $snapshotBuy * (float) ($detail->conversion_factor ?? 1)
                );
            }
        }

        // Receivable replay, grouped by receivable row.
        $byReceivable = [];

        foreach ($returns as $salesReturn) {
            $transaction = $salesReturn->transaction;

            if ($transaction === null || $transaction->payment_method !== 'pay_later' || $transaction->receivable === null) {
                continue;
            }

            $byReceivable[$transaction->receivable->id][] = $salesReturn;
        }

        foreach ($byReceivable as $receivableId => $group) {
            $receivable = $group[0]->transaction->receivable;
            $running = (int) $group[0]->transaction->grand_total;

            foreach ($group as $salesReturn) {
                $reduction = max(0, min(
                    (int) $salesReturn->total_return_amount - (int) $salesReturn->refund_amount - (int) $salesReturn->credited_amount,
                    $running,
                ));
                $result[$salesReturn->id]['receivable_reduction'] = $reduction;
                $running -= $reduction;
            }

            if ($running !== (int) $receivable->total) {
                foreach ($group as $salesReturn) {
                    $result[$salesReturn->id]['valid'] = false;
                }
            }
        }

        // Consignment-payable replay, grouped by (transaction, supplier).
        $byPayable = [];

        foreach ($returns as $salesReturn) {
            $transaction = $salesReturn->transaction;

            if ($transaction === null) {
                continue;
            }

            foreach ($salesReturn->items as $item) {
                $detail = $item->transactionDetail;

                if (! $detail || ! $detail->is_consignment || ! $detail->supplier_id || (int) $item->qty_return <= 0) {
                    continue;
                }

                $byPayable[$transaction->id.'|'.$detail->supplier_id][] = [$salesReturn, $item];
            }
        }

        foreach ($byPayable as $key => $pairs) {
            [$transactionId, $supplierId] = explode('|', $key);
            $transaction = Transaction::with('details')->find((int) $transactionId);

            $payable = Payable::query()
                ->where('transaction_id', (int) $transactionId)
                ->where('supplier_id', (int) $supplierId)
                ->first();

            if ($transaction === null || $payable === null) {
                continue; // live rule: no payable, no reduction
            }

            $initial = 0;

            foreach ($transaction->details as $detail) {
                if ($detail->is_consignment && (int) $detail->supplier_id === (int) $supplierId) {
                    $initial += (int) round((int) $detail->qty * (int) $detail->buy_price * (float) ($detail->conversion_factor ?? 1));
                }
            }

            $payments = PayablePayment::query()
                ->where('payable_id', $payable->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $running = $initial;
            $valid = true;

            // Pairs arrive in return order (returns were ordered by
            // completed_at); replay each return's items for this supplier.
            $groupedByReturn = [];

            foreach ($pairs as [$salesReturn, $item]) {
                $groupedByReturn[$salesReturn->id][] = $item;
            }

            foreach ($groupedByReturn as $returnId => $items) {
                $salesReturn = SalesReturn::find($returnId);
                $returnAmount = 0;

                foreach ($items as $item) {
                    $detail = $item->transactionDetail;
                    $returnAmount += (int) round(
                        (int) $item->qty_return * (int) ($detail->buy_price ?? 0) * (float) ($detail->conversion_factor ?? 1)
                    );
                }

                $paidBefore = 0;

                foreach ($payments as $payment) {
                    if ($payment->created_at !== null && $salesReturn->completed_at !== null && $payment->created_at->lt($salesReturn->completed_at)) {
                        $paidBefore += (int) $payment->amount;
                    }
                }

                $oldTotal = $running;
                $newTotal = max($paidBefore, $oldTotal - $returnAmount);
                $result[$returnId]['consignment_reduction'] += $oldTotal - $newTotal;
                $running = $newTotal;
            }

            if ($running !== (int) $payable->total) {
                $valid = false;
            }

            if (! $valid) {
                foreach (array_keys($groupedByReturn) as $returnId) {
                    $result[$returnId]['valid'] = false;
                }
            }
        }

        return $result;
    }

    private function backfillSalesReturns(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $returns = SalesReturn::query()
            ->with('transaction')
            ->where('status', 'completed')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        // Non-completed returns are not journaled by the live hook either.
        $report['skipped']['not_completed'] = (int) SalesReturn::query()
            ->where('status', '!=', 'completed')
            ->count();

        if ($returns->isEmpty()) {
            return $report;
        }

        $reconstructed = $this->reconstructSalesReturns();

        foreach ($returns as $salesReturn) {
            $transaction = $salesReturn->transaction;
            $returnBusiness = $salesReturn->business_id ?? ($transaction !== null ? $this->businessForTransaction($transaction) : null);

            if ($businessId !== null && (int) $returnBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_SALES_RETURN, $salesReturn->id)) {
                $report['already']++;

                continue;
            }

            if ($transaction === null) {
                $this->skip($report, 'missing_transaction');

                continue;
            }

            $parts = $reconstructed[$salesReturn->id] ?? null;

            if ($parts === null || ! $parts['valid']) {
                $this->skip($report, 'reconstruction_mismatch');

                continue;
            }

            if ($parts['refund'] + $parts['receivable_reduction'] + $parts['owned_restock'] + $parts['consignment_reduction'] <= 0) {
                $this->skip($report, 'zero_amount');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postSalesReturnJournal(
                $salesReturn,
                $returnBusiness,
                $parts['refund'],
                $parts['receivable_reduction'],
                $parts['owned_restock'],
                $parts['consignment_reduction'],
            );

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }

    /**
     * Replay supplier payables chronologically (receivings overwrite the
     * total and reset paid, exactly like GoodsReceivingService's
     * updateOrCreate; payments add to paid; returns split by the unpaid
     * portion) and cross-check the final simulated totals against the
     * stored payable. Returns: [payableId => [returnId => [returnAmount,
     * payableReduction, paidPortion]] | null when unreconstructable].
     */
    private function reconstructSupplierReturns(): array
    {
        $result = [];

        $payables = Payable::query()
            ->whereNotNull('purchase_order_id')
            ->with(['purchaseOrder.items'])
            ->get();

        foreach ($payables as $payable) {
            $order = $payable->purchaseOrder;

            if ($order === null) {
                continue;
            }

            $receivings = GoodsReceiving::query()
                ->with('items')
                ->where('purchase_order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $payments = PayablePayment::query()
                ->where('payable_id', $payable->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $returns = SupplierReturn::query()
                ->with('items')
                ->where('payable_id', $payable->id)
                ->where('status', 'completed')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $events = [];

            foreach ($receivings as $receiving) {
                $events[] = ['at' => $receiving->created_at, 'id' => $receiving->id, 'kind' => 'receiving', 'row' => $receiving];
            }

            foreach ($payments as $payment) {
                $events[] = ['at' => $payment->created_at, 'id' => $payment->id, 'kind' => 'payment', 'row' => $payment];
            }

            foreach ($returns as $supplierReturn) {
                $events[] = ['at' => $supplierReturn->created_at, 'id' => $supplierReturn->id, 'kind' => 'return', 'row' => $supplierReturn];
            }

            usort($events, fn ($a, $b) => ($a['at']?->timestamp ?? 0) <=> ($b['at']?->timestamp ?? 0) ?: $a['id'] <=> $b['id']);

            $total = 0;
            $paid = 0;
            $splits = [];

            foreach ($events as $event) {
                if ($event['kind'] === 'receiving') {
                    $value = $this->receivingValue($event['row']);

                    if ($value <= 0) {
                        $value = (int) $order->items->sum(fn ($item) => (int) $item->qty_ordered * (int) $item->unit_price);
                    }

                    // Live updateOrCreate overwrites the total and resets
                    // paid on every receiving for the same PO.
                    $total = $value;
                    $paid = 0;
                } elseif ($event['kind'] === 'payment') {
                    $paid += (int) $event['row']->amount;
                } else {
                    $returnAmount = (int) $event['row']->items->sum(fn ($item) => (int) $item->qty_returned * (int) $item->unit_price);
                    $unpaidBefore = max(0, $total - $paid);
                    $payableReduction = min($returnAmount, $unpaidBefore);
                    $splits[$event['row']->id] = [
                        'return_amount' => $returnAmount,
                        'payable_reduction' => $payableReduction,
                        'paid_portion' => $returnAmount - $payableReduction,
                    ];
                    $total = max(0, $total - $returnAmount);
                }
            }

            if ((int) round($total) === (int) round($payable->total) && (int) round($paid) === (int) round($payable->paid)) {
                $result[$payable->id] = $splits;
            } else {
                $result[$payable->id] = null;
            }
        }

        return $result;
    }

    private function backfillSupplierReturns(?int $businessId, bool $dryRun): array
    {
        $report = $this->newTypeReport();

        $returns = SupplierReturn::query()
            ->with(['items', 'payable'])
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $report['skipped']['not_completed'] = (int) SupplierReturn::query()
            ->where('status', '!=', 'completed')
            ->count();

        if ($returns->isEmpty()) {
            return $report;
        }

        $reconstructed = $this->reconstructSupplierReturns();

        foreach ($returns as $supplierReturn) {
            $returnBusiness = $supplierReturn->business_id ?? $supplierReturn->payable?->business_id;

            if ($businessId !== null && (int) $returnBusiness !== $businessId) {
                continue;
            }

            if ($this->alreadyJournaled(JournalService::SOURCE_SUPPLIER_RETURN, $supplierReturn->id)) {
                $report['already']++;

                continue;
            }

            if ($supplierReturn->payable === null) {
                $this->skip($report, 'no_payable');

                continue;
            }

            if ($supplierReturn->payable->purchase_order_id === null) {
                $this->skip($report, 'manual_payable');

                continue;
            }

            $splits = $reconstructed[$supplierReturn->payable->id] ?? null;

            if ($splits === null || ! isset($splits[$supplierReturn->id])) {
                $this->skip($report, 'reconstruction_mismatch');

                continue;
            }

            $split = $splits[$supplierReturn->id];

            if ($split['return_amount'] <= 0) {
                $this->skip($report, 'zero_amount');

                continue;
            }

            if ($dryRun) {
                $report['posted']++;

                continue;
            }

            $entry = $this->journals->postSupplierReturnJournal(
                $supplierReturn,
                $returnBusiness,
                $split['return_amount'],
                $split['payable_reduction'],
                $split['paid_portion'],
            );

            if ($entry === null) {
                $this->skip($report, 'post_returned_null');
            } else {
                $report['posted']++;
            }
        }

        return $report;
    }
}
