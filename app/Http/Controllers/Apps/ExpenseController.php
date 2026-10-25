<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly JournalService $journalService,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        // get expenses
        $expenses = Expense::with(['category:id,name', 'warehouse:id,name', 'user:id,name'])
            ->when(request()->search, function ($expenses) {
                $expenses = $expenses->where(function ($q) {
                    $q->where('note', 'like', '%'.request()->search.'%')
                        ->orWhere('proof_reference', 'like', '%'.request()->search.'%');
                });
            })
            ->when(request()->category_id, fn ($q) => $q->where('expense_category_id', request()->category_id))
            ->when(request()->source, fn ($q) => $q->where('source', request()->source))
            ->when(request()->warehouse_id, fn ($q) => $q->where('warehouse_id', request()->warehouse_id))
            ->when(request()->start_date, fn ($q) => $q->whereDate('date', '>=', request()->start_date))
            ->when(request()->end_date, fn ($q) => $q->whereDate('date', '<=', request()->end_date))
            ->latest('date')
            ->paginate($this->perPage())
            ->withQueryString();

        // return inertia
        return Inertia::render('Dashboard/Expenses/Index', [
            'expenses' => $expenses,
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return Inertia::render('Dashboard/Expenses/Create', [
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        /**
         * validate — direct drawer creation is rejected: drawer expenses
         * must come through a shift cash movement, which creates the Expense
         * row atomically (guard 8.2a). Otherwise the same expense could be
         * recorded twice or without cash ever leaving the drawer.
         */
        $validated = $request->validate([
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'source' => ['required', 'in:'.implode(',', [Expense::SOURCE_BANK, Expense::SOURCE_OTHER_CASH])],
            'note' => ['nullable', 'string', 'max:255'],
            'proof_reference' => ['nullable', 'string', 'max:255'],
            'cashier_shift_id' => ['prohibited'],
        ]);

        // Spec §2.2: a category without an account mapping cannot be used
        // in an Expense — fail with a validation error, not a 500 from the
        // journal post.
        $category = ExpenseCategory::findOrFail($validated['expense_category_id']);

        try {
            $this->journalService->expenseAccountCode($category);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'expense_category_id' => $e->getMessage(),
            ]);
        }

        // Create expense and journal it in the same transaction (spec §3.7):
        // direct bank/other_cash creation, same as the drawer dual-create.
        $expense = DB::transaction(function () use ($validated, $request) {
            $expense = Expense::create([
                'expense_category_id' => $validated['expense_category_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'date' => $validated['date'],
                'amount' => $validated['amount'],
                'source' => $validated['source'],
                'note' => $validated['note'] ?? null,
                'proof_reference' => $validated['proof_reference'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            $this->journalService->postExpenseJournal($expense, $expense->business_id);

            return $expense;
        });

        $this->auditLogService->log(
            event: 'expense.created',
            module: 'expenses',
            auditable: $expense,
            description: 'Beban dicatat.',
        );

        // redirect
        return to_route('expenses.index')->with('success', 'Beban berhasil dicatat.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(Expense $expense)
    {
        return Inertia::render('Dashboard/Expenses/Edit', [
            'expense' => $expense->load(['category:id,name', 'warehouse:id,name']),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, Expense $expense)
    {
        /**
         * validate — source and cashier_shift_id are immutable: both decide
         * the cash effect, and changing them would break reconciliation of
         * shifts that are already closed.
         */
        $validated = $request->validate([
            'expense_category_id' => ['sometimes', 'integer', 'exists:expense_categories,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'proof_reference' => ['nullable', 'string', 'max:255'],
            'source' => ['prohibited'],
            'cashier_shift_id' => ['prohibited'],
        ]);

        // update expense
        $expense->update($validated);

        $this->auditLogService->log(
            event: 'expense.updated',
            module: 'expenses',
            auditable: $expense,
            description: 'Beban diperbarui.',
        );

        // redirect
        return to_route('expenses.index')->with('success', 'Beban berhasil diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * Deleting a drawer expense never deletes its cash movement: the
     * movement's expense_id is cleared by the foreign key (nullOnDelete)
     * and the cash record stays for shift reconciliation.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        // find by ID
        $expense = Expense::findOrFail($id);

        // delete
        $expense->delete();

        $this->auditLogService->log(
            event: 'expense.deleted',
            module: 'expenses',
            auditable: $expense,
            description: 'Beban dihapus.',
        );

        // redirect
        return to_route('expenses.index')->with('success', 'Beban berhasil dihapus.');
    }
}
