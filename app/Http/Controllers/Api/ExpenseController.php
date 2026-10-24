<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Http\Traits\ApiResponder;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponder;

    /**
     * GET /api/v1/expenses
     */
    public function index(Request $request): JsonResponse
    {
        $expenses = Expense::query()
            ->with(['category:id,name', 'warehouse:id,name', 'user:id,name'])
            ->when($request->input('category_id'), fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($request->input('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->input('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->input('start_date'), fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($request->input('end_date'), fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->latest('date')
            ->paginate($this->perPage());

        return $this->paginated($expenses, ExpenseResource::collection($expenses));
    }

    /**
     * POST /api/v1/expenses
     *
     * Direct drawer creation is rejected (guard 8.2a): drawer expenses must
     * come through a shift cash movement, which creates the Expense row
     * atomically.
     */
    public function store(Request $request): JsonResponse
    {
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

        return $this->created(new ExpenseResource($expense->load(['category:id,name'])), 'Beban berhasil dicatat');
    }

    /**
     * GET /api/v1/expenses/{expense}
     */
    public function show(Request $request, Expense $expense): JsonResponse
    {
        return $this->ok(new ExpenseResource($expense->load(['category:id,name', 'warehouse:id,name', 'user:id,name'])));
    }

    /**
     * PUT/PATCH /api/v1/expenses/{expense}
     *
     * Source and cashier_shift_id are immutable: both decide the cash effect.
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
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

        $expense->update($validated);

        return $this->ok(new ExpenseResource($expense->load(['category:id,name'])), 'Beban berhasil diperbarui');
    }

    /**
     * DELETE /api/v1/expenses/{expense}
     *
     * Deleting a drawer expense never deletes its cash movement.
     */
    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $expense->delete();

        return $this->ok(null, 'Beban berhasil dihapus');
    }
}
