<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\ExpenseReportService;
use App\Services\PphService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class ExpenseReportController extends Controller
{
    public function __construct(
        private readonly ExpenseReportService $expenseReportService,
        private readonly PphService $pphService,
    ) {}

    /**
     * Expense report: Expense rows only, grouped by category.
     */
    public function expenses(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $expenses = Expense::with(['category:id,name', 'warehouse:id,name', 'user:id,name'])
            ->when($request->input('category_id'), fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->input('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->input('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($startDate, fn ($q) => $q->whereDate('date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('date', '<=', $endDate))
            ->latest('date')
            ->paginate($this->perPage())
            ->withQueryString();

        $summary = $this->expenseReportService->expensesByCategory($startDate, $endDate);

        return Inertia::render('Dashboard/Reports/Expenses', [
            'expenses' => $expenses,
            'summary' => $summary,
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['category_id', 'source', 'warehouse_id', 'start_date', 'end_date']),
        ]);
    }

    /**
     * Lite profit & loss: revenue, estimated HPP, gross profit, expenses
     * per category, signed cash difference, net profit.
     */
    public function profitLoss(Request $request)
    {
        $summary = $this->expenseReportService->profitLossLite(
            $request->input('start_date'),
            $request->input('end_date'),
        );

        return Inertia::render('Dashboard/Reports/ProfitLoss', [
            'summary' => $summary,
            'filters' => $request->only(['start_date', 'end_date']),
        ]);
    }

    /**
     * Fase 1 slice 3 (spec §3.10, locked Q4): manual monthly PPh action
     * from the laba-rugi page. Accrual only; the payment journal is
     * posted via `php artisan accounting:pph <bulan> --pay`.
     */
    public function storePph(Request $request)
    {
        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $businessId = app(BusinessContext::class)->businessId();

        try {
            $entry = $this->pphService->accrue($businessId, $validated['month'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('reports.profit-loss.index')->with('error', $e->getMessage());
        }

        if ($entry === null) {
            return redirect()->route('reports.profit-loss.index')
                ->with('success', 'Tidak ada PPh untuk bulan '.$validated['month'].' (omset nol).');
        }

        return redirect()->route('reports.profit-loss.index')
            ->with('success', 'Jurnal PPh Final bulan '.$validated['month'].' berhasil dibuat.');
    }
}
