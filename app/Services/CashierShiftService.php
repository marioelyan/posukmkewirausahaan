<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function getActiveShiftForUser(int $userId): ?CashierShift
    {
        return $this->activeShiftQuery($userId)
            ->with(['user:id,name', 'openedBy:id,name'])
            ->first();
    }

    public function requireActiveShiftForUser(int $userId, bool $lockForUpdate = false): CashierShift
    {
        $query = $this->activeShiftQuery($userId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $shift = $query->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => 'Shift kasir belum dibuka.',
            ]);
        }

        return $shift;
    }

    /**
     * Base query for a user's open shift, constrained to the verified active
     * business when tenancy is enabled.
     *
     * A user may be a member of several businesses. `CashierShift` now stores a
     * `business_id` and carries the fail-closed `BelongsToBusiness` global
     * scope, so `CashierShift::query()` is already limited to the active
     * business and returns nothing when tenancy is on but no context resolved.
     */
    private function activeShiftQuery(int $userId): Builder
    {
        return CashierShift::query()
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at');
    }

    public function openShift(User $cashier, User $actor, int $openingCash, ?string $notes = null, ?int $warehouseId = null): CashierShift
    {
        // The "one open shift per cashier" invariant is intentionally global
        // rather than per-business: the cashier must close the shift in one
        // business before opening one in another.
        $existing = CashierShift::query()
            ->withoutGlobalScope('business')
            ->open()
            ->where('user_id', $cashier->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'opening_cash' => 'Kasir ini masih memiliki shift aktif.',
            ]);
        }

        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $actor->id,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'expected_cash' => $openingCash,
            'notes' => $notes,
            'warehouse_id' => $warehouseId,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    public function calculateSummary(CashierShift $shift): array
    {
        $transactions = Transaction::query()
            ->where('cashier_shift_id', $shift->id);

        $salesReturns = SalesReturn::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('status', 'completed');

        $cashSalesTotal = (int) (clone $transactions)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        // Split payments: cash tender portions must count toward the drawer,
        // the parent row's payment_method ('split') puts grand_total in the non-cash bucket.
        $splitCashTenderTotal = (int) TransactionTender::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('cashier_shift_id', $shift->id)
                ->where('payment_method', 'split')
                ->where('payment_status', 'paid'))
            ->where('method', TransactionTender::METHOD_CASH)
            ->sum('cash_received');

        $cashSalesTotal += $splitCashTenderTotal;

        $nonCashSalesTotal = (int) (clone $transactions)
            ->where('payment_method', '!=', 'cash')
            ->sum('grand_total');

        // Split payments: non-cash portion = grand_total minus cash tenders.
        $nonCashSalesTotal -= $splitCashTenderTotal;

        $cashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', 'refund_cash')
            ->sum('refund_amount');

        $nonCashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', '!=', 'refund_cash')
            ->sum(DB::raw('COALESCE(credited_amount, 0)'));

        $transactionsCount = (int) (clone $transactions)->count();
        $salesReturnsCount = (int) (clone $salesReturns)->count();

        $cashInTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_IN)
            ->sum('amount');
        $cashOutTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->sum('amount');

        $expectedCash = (int) $shift->opening_cash + $cashSalesTotal - $cashRefundTotal + $cashInTotal - $cashOutTotal;

        return [
            'cash_sales_total' => $cashSalesTotal,
            'non_cash_sales_total' => $nonCashSalesTotal,
            'cash_refund_total' => $cashRefundTotal,
            'non_cash_refund_total' => $nonCashRefundTotal,
            'cash_in_total' => $cashInTotal,
            'cash_out_total' => $cashOutTotal,
            'transactions_count' => $transactionsCount,
            'sales_returns_count' => $salesReturnsCount,
            'expected_cash' => $expectedCash,
        ];
    }

    public function recordCashMovement(CashierShift $shift, User $actor, string $type, int $amount, ?string $note = null, ?string $kind = null, ?int $expenseCategoryId = null): ShiftCashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift sudah ditutup, tidak dapat mencatat pergerakan kas.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih besar dari nol.',
            ]);
        }

        if (! in_array($type, [ShiftCashMovement::TYPE_IN, ShiftCashMovement::TYPE_OUT], true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipe pergerakan kas tidak valid.',
            ]);
        }

        // Kind is optional for programmatic callers (legacy rows carry NULL and
        // are displayed as `lainnya`), but when given it must belong to the
        // movement type. Only `out + beban` creates an Expense row.
        if ($kind !== null) {
            $allowed = $type === ShiftCashMovement::TYPE_OUT
                ? ShiftCashMovement::KIND_OUT
                : ShiftCashMovement::KIND_IN;

            if (! in_array($kind, $allowed, true)) {
                throw ValidationException::withMessages([
                    'kind' => 'Jenis pergerakan kas tidak valid untuk tipe ini.',
                ]);
            }
        }

        $category = null;

        if ($type === ShiftCashMovement::TYPE_OUT && $kind === 'beban') {
            $category = ExpenseCategory::query()->find($expenseCategoryId);

            if (! $category) {
                throw ValidationException::withMessages([
                    'expense_category_id' => 'Kategori beban wajib diisi untuk kas keluar jenis beban.',
                ]);
            }
        }

        return DB::transaction(function () use ($shift, $actor, $type, $amount, $note, $kind, $category) {
            $movement = ShiftCashMovement::create([
                'cashier_shift_id' => $shift->id,
                'type' => $type,
                'kind' => $kind,
                'amount' => $amount,
                'note' => $note,
                'user_id' => $actor->id,
            ]);

            // Dual-create: a drawer expense is one economic event recorded as
            // one cash movement plus one Expense row. Other kinds never touch
            // the expense book, so setoran/talangan/pelunasan cannot deflate profit.
            if ($type === ShiftCashMovement::TYPE_OUT && $kind === 'beban' && $category) {
                $expense = Expense::create([
                    'expense_category_id' => $category->id,
                    'warehouse_id' => $shift->warehouse_id,
                    'cashier_shift_id' => $shift->id,
                    'date' => now()->toDateString(),
                    'amount' => $amount,
                    'source' => Expense::SOURCE_DRAWER,
                    'note' => $note,
                    'user_id' => $actor->id,
                ]);

                $movement->update(['expense_id' => $expense->id]);
            }

            return $movement->fresh();
        });
    }

    public function closeShift(
        CashierShift $shift,
        User $actor,
        int $actualCash,
        ?string $closeNotes = null,
        bool $forceClose = false
    ): CashierShift {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
            ]);
        }

        return DB::transaction(function () use ($shift, $actor, $actualCash, $closeNotes, $forceClose) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
                ]);
            }

            $summary = $this->calculateSummary($lockedShift);
            $cashDifference = $actualCash - $summary['expected_cash'];

            $lockedShift->update([
                'actual_cash' => $actualCash,
                'expected_cash' => $summary['expected_cash'],
                'cash_sales_total' => $summary['cash_sales_total'],
                'non_cash_sales_total' => $summary['non_cash_sales_total'],
                'cash_refund_total' => $summary['cash_refund_total'],
                'non_cash_refund_total' => $summary['non_cash_refund_total'],
                'cash_in_total' => $summary['cash_in_total'],
                'cash_out_total' => $summary['cash_out_total'],
                'transactions_count' => $summary['transactions_count'],
                'sales_returns_count' => $summary['sales_returns_count'],
                'cash_difference' => $cashDifference,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_notes' => $closeNotes,
                'status' => $forceClose
                    ? CashierShift::STATUS_FORCE_CLOSED
                    : CashierShift::STATUS_CLOSED,
            ]);

            return $lockedShift->fresh(['user:id,name', 'openedBy:id,name', 'closedBy:id,name']);
        });
    }

    public function summarizeForDisplay(?CashierShift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        $summary = $this->calculateSummary($shift);

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opening_cash' => (int) $shift->opening_cash,
            'opened_at' => optional($shift->opened_at)?->toISOString(),
            'notes' => $shift->notes,
            'warehouse' => $shift->warehouse ? [
                'id' => $shift->warehouse->id,
                'code' => $shift->warehouse->code,
                'name' => $shift->warehouse->name,
            ] : null,
            'user' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ] : null,
            ...$summary,
        ];
    }

    public function visibleToUser(Builder $query, User $user): Builder
    {
        // `$query` is already limited to the active business by the model's
        // business global scope, so every branch below inherits the boundary.
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->can('cashier-shifts-force-close')) {
            if (Outlet::active()->count() <= 1) {
                return $query;
            }

            $warehouseIds = $this->outletAccessService->warehousesFor($user)->pluck('id')->all();

            return $query->where(function (Builder $query) use ($user, $warehouseIds) {
                $query->where('user_id', $user->id);
                if ($warehouseIds !== []) {
                    $query->orWhereIn('warehouse_id', $warehouseIds);
                }
            });
        }

        return $query->where('user_id', $user->id);
    }
}
