<?php

namespace App\Support\Tenancy;

use App\Services\BusinessContext;
use App\Services\BusinessHierarchyService;
use App\Services\FinancialOwnershipService;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and verifies which business owns a record, using the verified
 * server-side business context (never a client-supplied id).
 *
 * Ownership strategy is table-specific and mirrors
 * `docs/architecture/multi-business-data-ownership-matrix.md`:
 *
 *  - direct  : the table carries a `business_id` column (master data, outlets)
 *  - warehouse: `business_id` when set, otherwise via `outlet_id`
 *  - outlet  : via `outlets.business_id`
 *
 * When multi-business is disabled every check passes, preserving legacy
 * single-tenant behaviour.
 */
class TenantOwnership
{
    /** @var array<int, string> */
    private const DIRECT_TABLES = [
        'products',
        'categories',
        'customers',
        'suppliers',
        'units',
        'price_lists',
        'outlets',
    ];

    /** @var array<int, string> Tables owned transitively via `outlet_id`. */
    private const OUTLET_SCOPED_TABLES = [
        'bank_accounts',
        'payment_settings',
        'customer_vouchers',
        'customer_campaigns',
    ];

    /**
     * Operational tables that carry no `business_id` and are owned via their
     * `warehouse_id` (warehouse -> outlet -> business). Central/receiving
     * warehouses may have no outlet, in which case ownership cannot be proven
     * and the check fails closed.
     *
     * @var array<int, string>
     */
    private const WAREHOUSE_SCOPED_TABLES = [
        'purchase_orders',
        'goods_receivings',
        'stock_opnames',
        'transactions',
        'cashier_shifts',
        'carts',
        'product_batches',
        'sales_returns',
        'supplier_returns',
    ];

    /**
     * Tables that carry no ownership key of their own and are owned through a
     * parent row that is itself warehouse-scoped (payables -> purchase_order,
     * receivables -> transaction). When the parent link is missing (manual or
     * legacy records) ownership cannot be proven and the check fails closed
     * while tenancy is enabled.
     *
     * @var array<string, array{0: string, 1: string}> table => [parent table, foreign key]
     */
    private const VIA_PARENT_TABLES = [
        'payables' => ['purchase_orders', 'purchase_order_id'],
        'receivables' => ['transactions', 'transaction_id'],
    ];

    public function __construct(
        private BusinessContext $context,
        private BusinessHierarchyService $hierarchy,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('multi_business.enabled');
    }

    public function activeBusinessId(): ?int
    {
        return $this->context->businessId();
    }

    /**
     * True when the entity is owned by the active business.
     *
     * Returns true when tenancy is disabled (legacy), and false when tenancy is
     * enabled but there is no verified context (fail closed).
     */
    public function entityBelongsToActiveBusiness(string $table, int|string|null $id): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $businessId = $this->activeBusinessId();

        if ($businessId === null) {
            return false;
        }

        if ($id === null) {
            return false;
        }

        if ($table === 'warehouses') {
            return $this->hierarchy->warehouseIdBelongsToBusiness((int) $id, $businessId);
        }

        if (in_array($table, self::OUTLET_SCOPED_TABLES, true)) {
            $outletId = DB::table($table)->where('id', $id)->value('outlet_id');

            return $outletId !== null
                && $this->hierarchy->outletIdBelongsToBusiness((int) $outletId, $businessId);
        }

        if (in_array($table, self::WAREHOUSE_SCOPED_TABLES, true)) {
            $warehouseId = DB::table($table)->where('id', $id)->value('warehouse_id');

            return $warehouseId !== null
                && $this->hierarchy->warehouseIdBelongsToBusiness((int) $warehouseId, $businessId);
        }

        if (in_array($table, self::DIRECT_TABLES, true)) {
            return $this->directTableBelongsToBusiness($table, (int) $id, $businessId);
        }

        if (isset(self::VIA_PARENT_TABLES[$table])) {
            // FIN-01/05: persisted business_id + 'resolved' ownership is
            // authoritative. Rows that are not 'resolved' are quarantined and
            // never usable, even when the parent chain would prove ownership
            // (design §2.3 — quarantine blocks mutations and payments).
            [$parentTable, $foreignKey] = self::VIA_PARENT_TABLES[$table];
            $row = DB::table($table)->where('id', $id)
                ->first(['business_id', 'ownership_status', $foreignKey]);

            if ($row === null) {
                return false;
            }

            if ($row->ownership_status === FinancialOwnershipService::STATUS_RESOLVED
                && $row->business_id !== null) {
                return (int) $row->business_id === $businessId;
            }

            $evaluated = $row->ownership_status !== null
                && $row->ownership_status !== FinancialOwnershipService::STATUS_UNASSIGNED;

            if ($evaluated) {
                // Evaluated but quarantined (manual_required / ambiguous /
                // dangling / unsupported) — fail closed, no chain fallback.
                return false;
            }

            // Legacy/transition rows: never evaluated, parent chain is the
            // only evidence (verify the chain without leaking the row).
            $parentId = $row->{$foreignKey};

            return $parentId !== null
                && $this->entityBelongsToActiveBusiness($parentTable, (int) $parentId);
        }

        // Unknown/global table: no tenant ownership to enforce.
        return true;
    }

    private function directTableBelongsToBusiness(string $table, int $id, int $businessId): bool
    {
        return DB::table($table)
            ->where('id', $id)
            ->where('business_id', $businessId)
            ->exists();
    }
}
