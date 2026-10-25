<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\JournalReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Fase 1 slice 4 (spec §5): Buku Besar, Laba Rugi, and Neraca from
 * journals. Pages are installation-wide, matching the lite reports
 * (which have no business scope); the service still accepts an optional
 * business filter for programmatic use.
 */
class JournalReportController extends Controller
{
    public function __construct(
        private readonly JournalReportService $reports,
    ) {}

    public function ledger(Request $request)
    {
        return Inertia::render('Dashboard/Reports/Ledger', [
            'rows' => $this->reports->ledgerBalances(
                $request->input('start_date'),
                $request->input('end_date'),
                $this->businessFilter($request),
            ),
            'filters' => $request->only(['start_date', 'end_date', 'account']),
        ]);
    }

    public function profitLoss(Request $request)
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        return Inertia::render('Dashboard/Reports/JournalProfitLoss', [
            'summary' => $this->reports->profitLoss($start, $end, $this->businessFilter($request)),
            'reconciliation' => $this->reports->reconcileLite($start, $end),
            'filters' => $request->only(['start_date', 'end_date']),
        ]);
    }

    public function balanceSheet(Request $request)
    {
        return Inertia::render('Dashboard/Reports/BalanceSheet', [
            'summary' => $this->reports->balanceSheet(
                $request->input('end_date'),
                $this->businessFilter($request),
            ),
            'filters' => $request->only(['end_date']),
        ]);
    }

    private function businessFilter(Request $request): ?int
    {
        $business = $request->input('business');

        return $business === null || $business === '' ? null : (int) $business;
    }
}
