<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OutletAccessService
{
    public function canUseWarehouse(User $user, ?Warehouse $warehouse): bool
    {
        if (! $warehouse) {
            // Legacy installs allowed shifts without a warehouse assignment.
            return $this->legacySingleOutletBypass();
        }

        if (! $warehouse->is_active) {
            return false;
        }

        if (! $this->warehouseBelongsToActiveBusiness($warehouse)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $outletIds = $user->outlets()->pluck('outlets.id');

        // Backward compatibility: old single-outlet installs have no assignments yet.
        if ($outletIds->isEmpty()) {
            return $this->legacySingleOutletBypass();
        }

        return (bool) ($warehouse->outlet_id && $outletIds->contains($warehouse->outlet_id));
    }

    public function canSellAtWarehouse(User $user, ?Warehouse $warehouse): bool
    {
        return $this->canUseWarehouse($user, $warehouse)
            && (! $warehouse?->outlet_id && $this->legacySingleOutletBypass()
                || (bool) ($warehouse?->outlet ?? Outlet::find($warehouse?->outlet_id))?->is_sales_enabled);
    }

    /**
     * Resolve the warehouse that should receive a new product's stock.
     *
     * Priority: explicitly requested warehouse (caller must authorize it),
     * then the first sales warehouse the user can sell at (matches shift-open
     * semantics), then the first active warehouse the user can access, then
     * the active main warehouse, then any active warehouse. Never creates one.
     */
    public function defaultStockWarehouseFor(User $user, ?int $requestedWarehouseId = null): ?Warehouse
    {
        if ($requestedWarehouseId) {
            return Warehouse::find($requestedWarehouseId);
        }

        return $this->salesWarehousesFor($user)->first()
            ?? $this->warehousesFor($user)->first()
            ?? Warehouse::active()->where('type', 'main')->orderBy('sort_order')->orderBy('code')->first()
            ?? Warehouse::active()->orderBy('sort_order')->orderBy('code')->first();
    }

    public function salesWarehousesFor(User $user): Collection
    {
        return $this->warehousesFor($user)
            ->filter(fn (Warehouse $warehouse) => ! $warehouse->outlet_id
                ? $this->legacySingleOutletBypass()
                : (bool) $warehouse->outlet?->is_sales_enabled)
            ->values();
    }

    public function warehousesFor(User $user): Collection
    {
        $query = $this->restrictToActiveBusiness(
            Warehouse::query()->active()->orderBy('sort_order')->orderBy('code')
        );

        if (! $user->isSuperAdmin()) {
            $outletIds = $user->outlets()->pluck('outlets.id')->map(fn ($id) => (int) $id);

            if ($outletIds->isNotEmpty()) {
                $query->whereIn('outlet_id', $outletIds);
            } elseif (! $this->legacySingleOutletBypass()) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->with('outlet:id,business_id,is_sales_enabled')
            ->get(['id', 'code', 'name', 'outlet_id', 'is_active', 'business_id']);
    }

    /**
     * Warehouse ids owned by the verified active business.
     *
     * Returns null while multi-business is disabled (legacy callers apply no
     * restriction), an empty collection when tenancy is enabled without a
     * verified context (fail closed), and the active business warehouse ids
     * otherwise. Ownership is the warehouse's own `business_id` or, before the
     * denormalisation backfill, the outlet it belongs to.
     *
     * @return \Illuminate\Support\Collection<int, int>|null
     */
    public function activeBusinessWarehouseIds(): ?\Illuminate\Support\Collection
    {
        if (! config('multi_business.enabled')) {
            return null;
        }

        $businessId = app(BusinessContext::class)->businessId();

        if ($businessId === null) {
            return collect();
        }

        return Warehouse::query()
            ->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)
                    ->orWhere(function ($query) use ($businessId) {
                        $query->whereNull('business_id')
                            ->whereHas('outlet', fn ($outlet) => $outlet->where('business_id', $businessId));
                    });
            })
            ->pluck('id');
    }

    public function defaultOutlet(User $user): ?Outlet
    {
        if ($user->isSuperAdmin()) {
            return $this->restrictToActiveBusiness(Outlet::active()->orderBy('code'))->first();
        }

        return $this->restrictToActiveBusiness(
            $user->outlets()
                ->where('outlets.is_active', true)
                ->orderByDesc('user_outlets.is_default')
                ->orderBy('outlets.code')
        )->first();
    }

    public function accessibleOutlets(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return $this->restrictToActiveBusiness(Outlet::active()->orderBy('code'))->get();
        }

        return $this->restrictToActiveBusiness(
            $user->outlets()
                ->where('outlets.is_active', true)
                ->orderByDesc('user_outlets.is_default')
                ->orderBy('outlets.code')
        )->get();
    }

    public function activeOutlet(Request $request): ?Outlet
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $shiftOutlet = CashierShift::query()
            ->with('warehouse.outlet')
            ->open()
            ->where('user_id', $user->id)
            ->latest('opened_at')
            ->first()?->warehouse?->outlet;

        if ($shiftOutlet) {
            return $shiftOutlet;
        }

        $outlets = $this->accessibleOutlets($user);
        $selectedId = (int) $request->session()->get('active_outlet_id');

        return $outlets->firstWhere('id', $selectedId) ?? $outlets->first() ?? $this->defaultOutlet($user);
    }

    public function hasActiveShiftInOtherOutlet(User $user, Outlet $outlet): bool
    {
        return (bool) CashierShift::query()
            ->where('user_id', $user->id)
            ->open()
            ->whereHas('warehouse', fn ($query) => $query->where('outlet_id', '!=', $outlet->id))
            ->exists();
    }

    /**
     * True when the user has an open shift whose warehouse belongs to a
     * business other than $businessId. Used to lock the business switcher the
     * same way {@see hasActiveShiftInOtherOutlet()} locks the outlet switcher:
     * switching business mid-shift would leave the shift pointing at a
     * warehouse outside the newly active business.
     */
    public function hasActiveShiftInOtherBusiness(User $user, int $businessId): bool
    {
        // Deliberately cross-business: the whole point is to inspect shifts that
        // fall OUTSIDE the target business, so the model's business scope (which
        // would hide them in the current context) must be bypassed. A shift with
        // no resolvable business is treated as "other" — the conservative
        // choice, since the switcher lock is a safety gate, not a convenience.
        return CashierShift::query()
            ->withoutGlobalScope('business')
            ->open()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', '!=', $businessId);
            })
            ->exists();
    }

    public function hasWarehouseHistory(Warehouse $warehouse): bool
    {
        $warehouseId = $warehouse->id;

        foreach ([
            'transactions',
            'carts',
            'stock_mutations',
            'cashier_shifts',
            'purchase_orders',
            'goods_receivings',
            'supplier_returns',
            'stock_opnames',
            'product_batches',
        ] as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'warehouse_id')
                && DB::table($table)->where('warehouse_id', $warehouseId)->exists()) {
                return true;
            }
        }

        return Schema::hasTable('stock_transfers')
            && (DB::table('stock_transfers')->where('source_warehouse_id', $warehouseId)->exists()
                || DB::table('stock_transfers')->where('destination_warehouse_id', $warehouseId)->exists());
    }

    public function hasOpenShift(Warehouse $warehouse): bool
    {
        return Schema::hasTable('cashier_shifts')
            && DB::table('cashier_shifts')
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 'open')
                ->exists();
    }

    private function legacySingleOutletBypass(): bool
    {
        // A lone active outlet is a convenience for legacy single-tenant
        // installs, but it is not proof of ownership. Under tenancy it must
        // never let a request skip the tenant ownership checks, so fail closed.
        if (config('multi_business.enabled')) {
            return false;
        }

        return config('security.outlet.legacy_single_outlet_bypass', true)
            && Outlet::active()->count() <= 1;
    }

    /**
     * Constrain an outlet/warehouse query to the verified active business when
     * multi-business is enabled. Fails closed (no rows) when tenancy is on but
     * there is no context, and is a no-op in legacy single-tenant mode.
     */
    private function restrictToActiveBusiness($query)
    {
        if (! config('multi_business.enabled')) {
            return $query;
        }

        $businessId = app(BusinessContext::class)->businessId();

        if ($businessId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('business_id', $businessId);
    }

    private function warehouseBelongsToActiveBusiness(Warehouse $warehouse): bool
    {
        if (! config('multi_business.enabled')) {
            return true;
        }

        return app(BusinessHierarchyService::class)
            ->warehouseBelongsToBusiness($warehouse, app(BusinessContext::class)->businessId());
    }
}
