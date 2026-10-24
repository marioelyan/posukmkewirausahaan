<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseCategoryResource;
use App\Http\Traits\ApiResponder;
use App\Models\ExpenseCategory;
use App\Support\Tenancy\TenantUnique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    use ApiResponder;

    /**
     * GET /api/v1/expense-categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate($this->perPage());

        return $this->paginated($categories, ExpenseCategoryResource::collection($categories));
    }

    /**
     * POST /api/v1/expense-categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantUnique::for('expense_categories', 'name')],
            'account_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = ExpenseCategory::create([
            'name' => $validated['name'],
            'account_code' => $validated['account_code'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->created(new ExpenseCategoryResource($category), 'Kategori beban berhasil dibuat');
    }

    /**
     * GET /api/v1/expense-categories/{expenseCategory}
     */
    public function show(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        return $this->ok(new ExpenseCategoryResource($expenseCategory->loadCount('expenses')));
    }

    /**
     * PUT/PATCH /api/v1/expense-categories/{expenseCategory}
     */
    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', TenantUnique::for('expense_categories', 'name', $expenseCategory->id)],
            'account_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $expenseCategory->update($validated);

        return $this->ok(new ExpenseCategoryResource($expenseCategory), 'Kategori beban berhasil diperbarui');
    }

    /**
     * DELETE /api/v1/expense-categories/{expenseCategory}
     */
    public function destroy(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        if ($expenseCategory->expenses()->exists()) {
            return $this->error('Kategori tidak dapat dihapus karena masih dipakai oleh data beban.', 422);
        }

        $expenseCategory->delete();

        return $this->ok(null, 'Kategori beban berhasil dihapus');
    }
}
