<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Warehouse;
use App\Services\ExpenseReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseReportController extends Controller
{
    public function __construct(private readonly ExpenseReportService $expenseReportService) {}

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
}
