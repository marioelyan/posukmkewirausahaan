<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;

/**
 * Validates the Business -> Outlet -> Warehouse hierarchy.
 *
 * A warehouse is considered owned by a business when its own `business_id`
 * matches, or (transitionally, before denormalisation) when the outlet it
 * belongs to is owned by that business.
 */
class BusinessHierarchyService
{
    public function outletBelongsToBusiness(?Outlet $outlet, ?int $businessId): bool
    {
        if (! $outlet || $businessId === null || $outlet->business_id === null) {
            return false;
        }

        return (int) $outlet->business_id === (int) $businessId;
    }

    public function warehouseBelongsToBusiness(?Warehouse $warehouse, ?int $businessId): bool
    {
        if (! $warehouse || $businessId === null) {
            return false;
        }

        if ($warehouse->business_id !== null) {
            return (int) $warehouse->business_id === (int) $businessId;
        }

        return $this->outletBelongsToBusiness($warehouse->outlet, $businessId);
    }

    public function outletIdBelongsToBusiness(?int $outletId, ?int $businessId): bool
    {
        if ($outletId === null || $businessId === null) {
            return false;
        }

        return $this->outletBelongsToBusiness(Outlet::find($outletId), $businessId);
    }

    public function warehouseIdBelongsToBusiness(?int $warehouseId, ?int $businessId): bool
    {
        if ($warehouseId === null || $businessId === null) {
            return false;
        }

        return $this->warehouseBelongsToBusiness(Warehouse::with('outlet')->find($warehouseId), $businessId);
    }

    public function outletsForBusiness(int $businessId): Collection
    {
        return Outlet::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->get();
    }

    public function warehousesForBusiness(int $businessId): Collection
    {
        return Warehouse::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }
}
