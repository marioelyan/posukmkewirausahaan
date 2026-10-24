<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseReportService
{
    /**
     * Expenses grouped by category for a period. The report counts ONLY
     * Expense rows — shift cash movements are a cash mirror, never a second
     * expense count (anti-double-count rule).
     */
    public function expensesByCategory(?string $startDate, ?string $endDate): array
    {
        $rows = Expense::query()
            ->with('category:id,name')
            ->select('expense_category_id', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->when($startDate, fn ($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('date', '<=', $endDate))
            ->groupBy('expense_category_id')
            ->orderByDesc('total')
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => [
                'expense_category_id' => $row->expense_category_id,
                'category_name' => $row->category?->name ?? '-',
                'total' => (int) $row->total,
            ])->all(),
            'total' => (int) $rows->sum('total'),
        ];
    }

    /**
     * Lite profit & loss for a period.
     *
     * Revenue is SUM(grand_total) of completed (paid) transactions — already
     * net of discounts, so discounts are memo only. HPP is an ESTIMATE from
     * current master buy_price (the exact per-transaction profit lives in the
     * profits table, which this report does not expose). Cash difference is
     * its own signed line, never an automatic Expense row.
     */
    public function profitLossLite(?string $startDate, ?string $endDate): array
    {
        $transactions = Transaction::query()
            ->completed()
            ->when($startDate, fn ($q) => $q->whereDate('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('created_at', '<=', $endDate));

        $revenue = (int) (clone $transactions)->sum('grand_total');
        $discount = (int) (clone $transactions)->sum('discount');

        $transactionIds = (clone $transactions)->pluck('id');

        // HPP estimate: qty x current master buy_price x conversion factor,
        // exactly the spec formula. Composite component costs are not exploded
        // here, which is part of why the figure stays labelled an estimate.
        $hppEstimate = 0;

        if ($transactionIds->isNotEmpty()) {
            $hppEstimate = (int) TransactionDetail::query()
                ->join('products', 'products.id', '=', 'transaction_details.product_id')
                ->whereIn('transaction_details.transaction_id', $transactionIds)
                ->sum(DB::raw('transaction_details.qty * products.buy_price * COALESCE(transaction_details.conversion_factor, 1)'));
        }

        $expenses = $this->expensesByCategory($startDate, $endDate);
        $grossProfit = $revenue - $hppEstimate;

        $cashDifference = (int) CashierShift::query()
            ->whereNotNull('closed_at')
            ->when($startDate, fn ($q) => $q->whereDate('closed_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('closed_at', '<=', $endDate))
            ->sum('cash_difference');

        $netProfit = $grossProfit - $expenses['total'] + $cashDifference;

        return [
            'revenue' => $revenue,
            'discount_memo' => $discount,
            'hpp_estimate' => $hppEstimate,
            'hpp_is_estimate' => true,
            'gross_profit' => $grossProfit,
            'expenses_by_category' => $expenses['rows'],
            'expenses_total' => $expenses['total'],
            'cash_difference' => $cashDifference,
            'net_profit' => $netProfit,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generated_at' => Carbon::now()->toDateTimeString(),
        ];
    }
}
