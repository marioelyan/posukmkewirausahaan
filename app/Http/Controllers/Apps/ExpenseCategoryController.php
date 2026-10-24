<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Services\AuditLogService;
use App\Support\Tenancy\TenantUnique;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        // get expense categories
        $categories = ExpenseCategory::withCount('expenses')
            ->when(request()->search, function ($categories) {
                $categories = $categories->where('name', 'like', '%'.request()->search.'%');
            })->latest()->paginate($this->perPage())->withQueryString();

        // return inertia
        return Inertia::render('Dashboard/ExpenseCategories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return Inertia::render('Dashboard/ExpenseCategories/Create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        /**
         * validate
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantUnique::for('expense_categories', 'name')],
            'account_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // create expense category
        $category = ExpenseCategory::create([
            'name' => $validated['name'],
            'account_code' => $validated['account_code'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->auditLogService->log(
            event: 'expense_category.created',
            module: 'expense_categories',
            auditable: $category,
            description: 'Kategori beban dibuat.',
        );

        // redirect
        return to_route('expense-categories.index')->with('success', 'Kategori beban berhasil ditambahkan.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(ExpenseCategory $expenseCategory)
    {
        return Inertia::render('Dashboard/ExpenseCategories/Edit', [
            'category' => $expenseCategory,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        /**
         * validate
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantUnique::for('expense_categories', 'name', $expenseCategory->id)],
            'account_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // update expense category
        $expenseCategory->update([
            'name' => $validated['name'],
            'account_code' => $validated['account_code'] ?? null,
            'is_active' => $validated['is_active'] ?? $expenseCategory->is_active,
        ]);

        $this->auditLogService->log(
            event: 'expense_category.updated',
            module: 'expense_categories',
            auditable: $expenseCategory,
            description: 'Kategori beban diperbarui.',
        );

        // redirect
        return to_route('expense-categories.index')->with('success', 'Kategori beban berhasil diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * A category in use by expense rows cannot be deleted: bookkeeping
     * rows must keep their classification.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        // find by ID
        $category = ExpenseCategory::findOrFail($id);

        if ($category->expenses()->exists()) {
            return to_route('expense-categories.index')->with('error', 'Kategori tidak dapat dihapus karena masih dipakai oleh data beban.');
        }

        // delete
        $category->delete();

        $this->auditLogService->log(
            event: 'expense_category.deleted',
            module: 'expense_categories',
            auditable: $category,
            description: 'Kategori beban dihapus.',
        );

        // redirect
        return to_route('expense-categories.index')->with('success', 'Kategori beban berhasil dihapus.');
    }
}
