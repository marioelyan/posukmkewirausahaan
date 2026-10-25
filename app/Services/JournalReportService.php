<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\PayablePayment;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 slice 4 (spec §5): Buku Besar, Laba Rugi, and Neraca computed
 * from journals — never from aggregates.
 *
 * Conventions:
 * - Balances are signed by account category: aset/beban accounts read
 *   debit minus credit; kewajiban/modal/pendapatan accounts read credit
 *   minus debit. A 4110 Retur Penjualan balance is therefore negative
 *   when returns dominate, and it subtracts from revenue naturally.
 * - Buku Besar opening balances are cumulative from the beginning of
 *   time; Laba Rugi uses period movements only; Neraca uses cumulative
 *   balances as of the end date, with Laba Tahun Berjalan computed (no
 *   closing entries exist in phase 1).
 * - Pendapatan Lainnya (4200) is presented as its own line above Laba
 *   Kotor: the spec's §5 arithmetic (4100 − 4110 − HPP) stays visible
 *   and complete.
 * - Reconciliation and cash proof are installation-wide: the lite report
 *   they compare against has no business scope.
 */
class JournalReportService
{
    public function __construct(
        private readonly ExpenseReportService $lite,
        private readonly JournalService $journals,
    ) {}

    /**
     * Raw debit/credit sums per account code for entries in scope.
     * Returns [code => ['debit' => int, 'credit' => int]].
     */
    private function sums(?string $from, ?string $to, ?int $businessId): array
    {
        $rows = JournalEntry::query()
            ->join('journal_lines', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->when($businessId !== null, fn ($query) => $query->where('journal_entries.business_id', $businessId))
            ->when($from !== null, fn ($query) => $query->whereDate('journal_entries.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('journal_entries.date', '<=', $to))
            ->groupBy('accounts.code')
            ->select(
                'accounts.code',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as credit'),
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->code] = ['debit' => (int) $row->debit, 'credit' => (int) $row->credit];
        }

        return $result;
    }

    /**
     * Raw sums per account code per entry source type.
     * Returns [code => [sourceType => ['debit' => int, 'credit' => int]]].
     */
    private function sumsBySource(?string $from, ?string $to): array
    {
        $rows = JournalEntry::query()
            ->join('journal_lines', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->when($from !== null, fn ($query) => $query->whereDate('journal_entries.date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('journal_entries.date', '<=', $to))
            ->groupBy('accounts.code', 'journal_entries.source_type')
            ->select(
                'accounts.code',
                'journal_entries.source_type',
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as credit'),
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->code][$row->source_type] = ['debit' => (int) $row->debit, 'credit' => (int) $row->credit];
        }

        return $result;
    }

    private function balanceOf(string $code, int $debit, int $credit): int
    {
        $category = Account::CHART[$code][1] ?? null;

        return in_array($category, [Account::CATEGORY_ASSET, Account::CATEGORY_EXPENSE], true)
            ? $debit - $credit
            : $credit - $debit;
    }

    private function codeSums(array $sums, string $code): array
    {
        return $sums[$code] ?? ['debit' => 0, 'credit' => 0];
    }

    private function codeBalance(array $sums, string $code): int
    {
        $row = $this->codeSums($sums, $code);

        return $this->balanceOf($code, $row['debit'], $row['credit']);
    }

    /**
     * Buku Besar: one row per chart account with opening balance
     * (cumulative before the period), period movements, and closing
     * balance. Pass null dates for all-time.
     */
    public function ledgerBalances(?string $start, ?string $end, ?int $businessId): array
    {
        $openingSums = $start === null ? [] : $this->sums(null, date('Y-m-d', strtotime($start.' -1 day')), $businessId);
        $movementSums = $this->sums($start, $end, $businessId);

        $rows = [];

        foreach (Account::CHART as $code => [$name, $category]) {
            $opening = $this->codeSums($openingSums, $code);
            $movement = $this->codeSums($movementSums, $code);
            $openingBalance = $this->balanceOf($code, $opening['debit'], $opening['credit']);
            $closingBalance = $this->balanceOf($code, $opening['debit'] + $movement['debit'], $opening['credit'] + $movement['credit']);

            $rows[] = [
                'code' => $code,
                'name' => $name,
                'category' => $category,
                'opening' => $openingBalance,
                'debit' => $movement['debit'],
                'credit' => $movement['credit'],
                'closing' => $closingBalance,
            ];
        }

        return $rows;
    }

    /**
     * Laba Rugi from journals for a period (pass null dates for all-time).
     */
    public function profitLoss(?string $start, ?string $end, ?int $businessId): array
    {
        $sums = $this->sums($start, $end, $businessId);

        $pendapatanPenjualan = $this->codeBalance($sums, '4100') + $this->codeBalance($sums, '4110');
        $pendapatanLainnya = $this->codeBalance($sums, '4200');
        $totalPendapatan = $pendapatanPenjualan + $pendapatanLainnya;
        $hpp = $this->codeBalance($sums, '5100');
        $labaKotor = $totalPendapatan - $hpp;

        $bebanRows = [];

        foreach (['5200', '5210', '5220', '5230', '5240', '5250', '5260', '5270', '5280'] as $code) {
            $bebanRows[] = [
                'code' => $code,
                'name' => Account::CHART[$code][0],
                'total' => $this->codeBalance($sums, $code),
            ];
        }

        $bebanTotal = array_sum(array_column($bebanRows, 'total'));
        $labaOperasional = $labaKotor - $bebanTotal;
        $pajak = $this->codeBalance($sums, '5300');
        $labaBersih = $labaOperasional - $pajak;

        return [
            'pendapatan_penjualan' => $pendapatanPenjualan,
            'pendapatan_penjualan_detail' => [
                ['code' => '4100', 'name' => Account::CHART['4100'][0], 'total' => $this->codeBalance($sums, '4100')],
                ['code' => '4110', 'name' => Account::CHART['4110'][0], 'total' => $this->codeBalance($sums, '4110')],
            ],
            'pendapatan_lainnya' => $pendapatanLainnya,
            'total_pendapatan' => $totalPendapatan,
            'hpp' => $hpp,
            'laba_kotor' => $labaKotor,
            'beban_rows' => $bebanRows,
            'beban_total' => $bebanTotal,
            'laba_operasional' => $labaOperasional,
            'pajak' => $pajak,
            'laba_bersih' => $labaBersih,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /**
     * Neraca as of an end date (null = all time). Laba Tahun Berjalan is
     * computed, not posted: cumulative revenue balances minus cumulative
     * expense balances. The balance check must equal 0: every journal
     * entry balances, so assets always equal liabilities + equity +
     * current-year profit, unless journal data was corrupted outside the
     * service.
     */
    public function balanceSheet(?string $end, ?int $businessId): array
    {
        $sums = $this->sums(null, $end, $businessId);

        $assetRows = [];

        foreach (['1100', '1110', '1120', '1130', '1140'] as $code) {
            $assetRows[] = ['code' => $code, 'name' => Account::CHART[$code][0], 'total' => $this->codeBalance($sums, $code)];
        }

        $liabilityRows = [];

        foreach (['2100', '2110', '2120', '2130'] as $code) {
            $liabilityRows[] = ['code' => $code, 'name' => Account::CHART[$code][0], 'total' => $this->codeBalance($sums, $code)];
        }

        $equityRows = [];

        foreach (['3100', '3110'] as $code) {
            $equityRows[] = ['code' => $code, 'name' => Account::CHART[$code][0], 'total' => $this->codeBalance($sums, $code)];
        }

        $assetsTotal = array_sum(array_column($assetRows, 'total'));
        $liabilitiesTotal = array_sum(array_column($liabilityRows, 'total'));
        $equityTotal = array_sum(array_column($equityRows, 'total'));

        $revenueTotal = $this->codeBalance($sums, '4100') + $this->codeBalance($sums, '4110') + $this->codeBalance($sums, '4200');

        $expenseTotal = 0;

        foreach (['5100', '5200', '5210', '5220', '5230', '5240', '5250', '5260', '5270', '5280', '5300'] as $code) {
            $expenseTotal += $this->codeBalance($sums, $code);
        }

        $labaBerjalan = $revenueTotal - $expenseTotal;
        $check = $assetsTotal - $liabilitiesTotal - $equityTotal - $labaBerjalan;

        return [
            'asset_rows' => $assetRows,
            'assets_total' => $assetsTotal,
            'liability_rows' => $liabilityRows,
            'liabilities_total' => $liabilitiesTotal,
            'equity_rows' => $equityRows,
            'equity_total' => $equityTotal,
            'laba_berjalan' => $labaBerjalan,
            'check' => $check,
            'as_of' => $end,
        ];
    }

    /**
     * Trial balance: total debits minus total credits over all entries in
     * scope. Must be 0.
     */
    public function trialBalance(?int $businessId): int
    {
        $row = JournalEntry::query()
            ->join('journal_lines', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->when($businessId !== null, fn ($query) => $query->where('journal_entries.business_id', $businessId))
            ->select(
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) as credit'),
            )
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    /**
     * Reconcile the journal P&L against the lite P&L for the same period
     * (installation scope; the lite report has no business scope).
     *
     * Every difference is explained by an independently computed bridge
     * component. A nonzero residual is a bug, not a "method difference" —
     * except HPP rounding: the lite SQL sum is unrounded while journals
     * round per detail line, so a few rupiah of HPP residual is rounding.
     */
    public function reconcileLite(?string $start, ?string $end): array
    {
        $journal = $this->profitLoss($start, $end, null);
        $lite = $this->lite->profitLossLite($start, $end);
        $sums = $this->sums($start, $end, null);
        $bySource = $this->sumsBySource($start, $end);

        $journalPendapatan = $journal['pendapatan_penjualan'];
        $journalHpp = $journal['hpp'];

        // Revenue bridges.
        $unjournaledPaid = (int) Transaction::query()
            ->completed()
            ->whereNotIn('payment_method', ['cash', 'pay_later'])
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->sum('grand_total');

        $unpaidCredit = (int) Transaction::query()
            ->where('payment_method', 'pay_later')
            ->where('payment_status', '!=', 'paid')
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->sum('grand_total');

        $returnsContra = $this->codeSums($sums, '4110')['debit'] - $this->codeSums($sums, '4110')['credit'];

        $residualPendapatan = (int) $lite['revenue'] - ($journalPendapatan + $unjournaledPaid - $unpaidCredit + $returnsContra);

        // HPP bridges.
        $journaledPaidIds = Transaction::query()
            ->completed()
            ->whereIn('payment_method', ['cash', 'pay_later'])
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->pluck('id');

        $priceDiff = 0;

        if ($journaledPaidIds->isNotEmpty()) {
            // Journaled sales at current prices minus journaled sales at
            // frozen snapshots. Covers cash-paid and pay_later-paid sales;
            // unpaid credit sales are handled by unpaidCreditHpp below.
            $priceDiff = (int) TransactionDetail::query()
                ->join('products', 'products.id', '=', 'transaction_details.product_id')
                ->whereIn('transaction_details.transaction_id', $journaledPaidIds)
                ->sum(DB::raw('transaction_details.qty * (products.buy_price - COALESCE(transaction_details.buy_price, 0)) * COALESCE(transaction_details.conversion_factor, 1)'));
        }

        $unjournaledIds = Transaction::query()
            ->completed()
            ->whereNotIn('payment_method', ['cash', 'pay_later'])
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->pluck('id');

        $unjournaledHpp = 0;

        if ($unjournaledIds->isNotEmpty()) {
            $unjournaledHpp = (int) TransactionDetail::query()
                ->join('products', 'products.id', '=', 'transaction_details.product_id')
                ->whereIn('transaction_details.transaction_id', $unjournaledIds)
                ->sum(DB::raw('transaction_details.qty * products.buy_price * COALESCE(transaction_details.conversion_factor, 1)'));
        }

        $unpaidCreditIds = Transaction::query()
            ->where('payment_method', 'pay_later')
            ->where('payment_status', '!=', 'paid')
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->pluck('id');

        $unpaidCreditHpp = 0;

        if ($unpaidCreditIds->isNotEmpty()) {
            $unpaidCreditHpp = (int) TransactionDetail::query()
                ->whereIn('transaction_id', $unpaidCreditIds)
                ->sum(DB::raw('qty * COALESCE(buy_price, 0) * COALESCE(conversion_factor, 1)'));
        }

        $returnCredits = $bySource['5100'][JournalService::SOURCE_SALES_RETURN]['credit'] ?? 0;

        $residualHpp = (int) $lite['hpp_estimate'] - ($journalHpp + $priceDiff + $unjournaledHpp - $unpaidCreditHpp + $returnCredits);

        // Expense bridges.
        $journalBebanEx = 0;

        foreach (['5200', '5210', '5220', '5230', '5240', '5250', '5260', '5270'] as $code) {
            $journalBebanEx += $this->codeBalance($sums, $code);
        }

        $lainnyaOut = $bySource['5270'][JournalService::SOURCE_MOVEMENT]['debit'] ?? 0;

        $unjournaledExpenses = (int) Expense::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entries')
                    ->where('journal_entries.source_type', JournalService::SOURCE_EXPENSE)
                    ->whereColumn('journal_entries.source_id', 'expenses.id');
            })
            ->when($start, fn ($query) => $query->whereDate('date', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('date', '<=', $end))
            ->sum('amount');

        $residualBeban = $journalBebanEx - (int) $lite['expenses_total'] - $lainnyaOut + $unjournaledExpenses;

        // Cash-difference bridge: same source, same closed_at basis.
        $cashOver = $bySource['4200'][JournalService::SOURCE_SHIFT]['credit'] ?? 0;
        $cashShort = $bySource['5280'][JournalService::SOURCE_SHIFT]['debit'] ?? 0;
        $cashEffect = $cashOver - $cashShort;
        $residualCash = (int) $lite['cash_difference'] - $cashEffect;

        // Other journal-only lines.
        $lainnyaIn = $bySource['4200'][JournalService::SOURCE_MOVEMENT]['credit'] ?? 0;
        $pph = $journal['pajak'];

        $journalNet = $journal['laba_bersih'];
        $liteNet = (int) $lite['net_profit'];
        $residualNet = $journalNet - ($liteNet + ($journalPendapatan - (int) $lite['revenue']) + $lainnyaIn - ($journalHpp - (int) $lite['hpp_estimate']) - ($journalBebanEx - (int) $lite['expenses_total']) + ($cashEffect - (int) $lite['cash_difference']) - $pph);

        return [
            'journal' => [
                'pendapatan' => $journalPendapatan,
                'hpp' => $journalHpp,
                'beban' => $journalBebanEx,
                'laba_bersih' => $journalNet,
            ],
            'lite' => [
                'revenue' => (int) $lite['revenue'],
                'hpp_estimate' => (int) $lite['hpp_estimate'],
                'expenses_total' => (int) $lite['expenses_total'],
                'cash_difference' => (int) $lite['cash_difference'],
                'net_profit' => $liteNet,
            ],
            'bridges' => [
                'unjournaled_paid_sales' => $unjournaledPaid,
                'unpaid_credit_sales' => $unpaidCredit,
                'returns_contra' => $returnsContra,
                'price_diff_snapshot_vs_current' => $priceDiff,
                'unjournaled_hpp' => $unjournaledHpp,
                'unpaid_credit_hpp' => $unpaidCreditHpp,
                'return_hpp_credits' => $returnCredits,
                'lainnya_out_movements' => $lainnyaOut,
                'unjournaled_expenses' => $unjournaledExpenses,
                'cash_effect' => $cashEffect,
                'lainnya_in' => $lainnyaIn,
                'pph' => $pph,
            ],
            'residuals' => [
                'pendapatan' => $residualPendapatan,
                'hpp' => $residualHpp,
                'beban' => $residualBeban,
                'selisih_kas' => $residualCash,
                'bersih' => $residualNet,
            ],
            'balanced' => $residualPendapatan === 0 && $residualHpp === 0 && $residualBeban === 0 && $residualCash === 0 && $residualNet === 0,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /**
     * Cash proof: Kas on hand computed independently from source
     * documents, compared against the journal Kas balance (1100).
     *
     * Every difference must be explained by an unjournaled cash-affecting
     * source: destination-less setoran, null-kind movements, pelunasan
     * without a payable, failed expense posts, and orphan beban movements
     * (cash left the drawer with no Expense row and no journal). PPh cash
     * payments are journal-native (no source document exists), so they
     * are read from their own payment journals on both sides by design.
     */
    public function cashProof(): array
    {
        $cashSales = (int) Transaction::query()->completed()->where('payment_method', 'cash')->sum('grand_total');
        $cashRecvPayments = (int) ReceivablePayment::query()->where('method', 'cash')->sum('amount');
        $cashInMovements = (int) ShiftCashMovement::query()->where('type', ShiftCashMovement::TYPE_IN)->sum('amount');
        $cashOver = (int) CashierShift::query()
            ->whereIn('status', [CashierShift::STATUS_CLOSED, CashierShift::STATUS_FORCE_CLOSED])
            ->whereNotNull('closed_at')
            ->where('cash_difference', '>', 0)
            ->sum('cash_difference');

        $refunds = (int) SalesReturn::query()->where('status', 'completed')->sum('refund_amount');
        $drawerExpenses = (int) Expense::query()->whereIn('source', [Expense::SOURCE_DRAWER, Expense::SOURCE_OTHER_CASH])->sum('amount');
        $cashOutMovements = (int) ShiftCashMovement::query()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->where('kind', '!=', 'beban')
            ->sum('amount');

        $payCash = 0;

        foreach (PayablePayment::query()->where('method', 'cash')->get() as $payment) {
            if (! $this->journals->isDrawerMirrorPayment($payment)) {
                $payCash += (int) $payment->amount;
            }
        }

        $cashShort = (int) CashierShift::query()
            ->whereIn('status', [CashierShift::STATUS_CLOSED, CashierShift::STATUS_FORCE_CLOSED])
            ->whereNotNull('closed_at')
            ->where('cash_difference', '<', 0)
            ->sum(DB::raw('ABS(cash_difference)'));

        $pphCash = (int) JournalEntry::query()
            ->join('journal_lines', 'journal_lines.entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', JournalService::SOURCE_PPH_PAYMENT)
            ->where('accounts.code', '1100')
            ->sum('journal_lines.credit');

        // Orphan beban movements: cash left the drawer, but no Expense row
        // backs them and no expense journal exists for their expense_id.
        // Their cash-out belongs in actual cash: the drawer really lost
        // that cash, even though no Expense row exists for it.
        $journaledExpenseIds = JournalEntry::query()
            ->where('source_type', JournalService::SOURCE_EXPENSE)
            ->pluck('source_id')
            ->all();

        $orphanBeban = 0;

        foreach (ShiftCashMovement::query()->where('type', ShiftCashMovement::TYPE_OUT)->where('kind', 'beban')->get(['expense_id', 'amount']) as $movement) {
            if ($movement->expense_id === null || ! in_array($movement->expense_id, $journaledExpenseIds, false)) {
                $orphanBeban += (int) $movement->amount;
            }
        }

        $actualCash = $cashSales + $cashRecvPayments + $cashInMovements + $cashOver
            - $refunds - $drawerExpenses - $cashOutMovements - $payCash - $cashShort - $pphCash - $orphanBeban;

        $journalKas = $this->codeBalance($this->sums(null, null, null), '1100');
        $gap = $actualCash - $journalKas;

        // Unexplained-gap components (all unjournaled by design or failure).
        $destlessSetoran = (int) ShiftCashMovement::query()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->where('kind', 'setoran')
            ->whereNull('setoran_destination')
            ->sum('amount');

        $nullKindIn = (int) ShiftCashMovement::query()->where('type', ShiftCashMovement::TYPE_IN)->whereNull('kind')->sum('amount');
        $nullKindOut = (int) ShiftCashMovement::query()->where('type', ShiftCashMovement::TYPE_OUT)->whereNull('kind')->sum('amount');
        $nullKindNet = $nullKindIn - $nullKindOut;

        $missingPayablePelunasan = (int) ShiftCashMovement::query()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->where('kind', 'pelunasan_hutang')
            ->where(function ($query) {
                $query->whereNull('payable_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('payables')
                            ->whereColumn('payables.id', 'shift_cash_movements.payable_id');
                    });
            })
            ->sum('amount');

        $failedCashExpenses = (int) Expense::query()
            ->whereIn('source', [Expense::SOURCE_DRAWER, Expense::SOURCE_OTHER_CASH])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entries')
                    ->where('journal_entries.source_type', JournalService::SOURCE_EXPENSE)
                    ->whereColumn('journal_entries.source_id', 'expenses.id');
            })
            ->sum('amount');

        $explainedGap = -$destlessSetoran + $nullKindNet - $missingPayablePelunasan - $failedCashExpenses - $orphanBeban;
        $residual = $gap - $explainedGap;

        return [
            'components' => [
                'cash_sales' => $cashSales,
                'cash_receivable_payments' => $cashRecvPayments,
                'cash_in_movements' => $cashInMovements,
                'cash_over' => $cashOver,
                'refunds' => $refunds,
                'drawer_expenses' => $drawerExpenses,
                'cash_out_movements' => $cashOutMovements,
                'pay_cash' => $payCash,
                'cash_short' => $cashShort,
                'pph_cash' => $pphCash,
                'orphan_beban' => $orphanBeban,
            ],
            'actual_cash' => $actualCash,
            'journal_kas' => $journalKas,
            'gap' => $gap,
            'explained' => [
                'setoran_no_destination' => $destlessSetoran,
                'null_kind_net' => $nullKindNet,
                'missing_payable_pelunasan' => $missingPayablePelunasan,
                'failed_cash_expenses' => $failedCashExpenses,
                'orphan_beban' => $orphanBeban,
            ],
            'explained_gap' => $explainedGap,
            'residual' => $residual,
        ];
    }
}
