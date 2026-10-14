<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY classifier for financial-record business ownership.
 *
 * Implements FIN-01 (Q7.1): a record is only proposed for automatic ownership
 * assignment when its complete, persisted parent chain resolves to exactly one
 * business. This class never writes to the database and never consults the
 * active business context, the acting user, a single-outlet count, a default
 * business, global settings, or any other non-persisted signal (the safety
 * constraints of Phase 2A).
 *
 * Verified ownership graph (see
 * `docs/architecture/financial-ownership-resolution-design.md`):
 *
 *   payables.purchase_order_id  -> purchase_orders.id
 *   receivables.transaction_id  -> transactions.id
 *   purchase_orders.warehouse_id / transactions.warehouse_id -> warehouses.id
 *   warehouses.business_id  (direct)
 *   warehouses.outlet_id     -> outlets.business_id (indirect)
 *
 * A warehouse is "owned" only when exactly one business is proven: either its
 * own non-null `business_id`, or, when that is null, the non-null
 * `business_id` of its outlet. A non-null `business_id` on the warehouse that
 * disagrees with its outlet's `business_id` is a conflict and fails closed.
 */
class FinancialOwnershipResolver
{
    /**
     * Financial records that carry no ownership key of their own and are owned
     * through a parent row.
     *
     * @var array<string, array{0: string, 1: string}> table => [parent table, foreign key]
     */
    private const CHAINS = [
        'payables' => ['purchase_orders', 'purchase_order_id'],
        'receivables' => ['transactions', 'transaction_id'],
    ];

    /**
     * Supported parent tables whose own ownership is derived from `warehouse_id`
     * (warehouse -> [business | outlet -> business]).
     *
     * @var array<int, string>
     */
    private const WAREHOUSE_SCOPED_PARENTS = [
        'purchase_orders',
        'transactions',
    ];

    public const R_AUTO_WAREHOUSE = 'auto.warehouse_business';

    public const R_AUTO_OUTLET = 'auto.outlet_business';

    public const R_MANUAL_NO_PARENT = 'manual.no_parent';

    public const R_DANGLING_PARENT = 'dangling.parent_missing';

    public const R_DANGLING_WAREHOUSE = 'dangling.warehouse_missing';

    public const R_DANGLING_OUTLET = 'dangling.outlet_missing';

    public const R_AMBIGUOUS_PARENT_WITHOUT_WAREHOUSE = 'ambiguous.parent_without_warehouse';

    public const R_AMBIGUOUS_UNOWNED_WAREHOUSE = 'ambiguous.unowned_warehouse';

    public const R_AMBIGUOUS_CONFLICT = 'ambiguous.business_conflict';

    public const R_ALREADY = 'resolved.already';

    public const R_ALREADY_CONFLICT = 'resolved.conflict';

    public const R_UNSUPPORTED = 'unsupported.chain';

    /** @var array<string, bool> */
    private array $hasBusinessIdColumn = [];

    /**
     * @return array<int, string>
     */
    public function supportedTables(): array
    {
        return array_keys(self::CHAINS);
    }

    public function isSupported(string $table): bool
    {
        return isset(self::CHAINS[$table]);
    }

    /**
     * Classify one record. Read-only.
     */
    public function resolve(string $table, int $id): FinancialOwnershipResult
    {
        if (! $this->isSupported($table) || ! Schema::hasTable($table)) {
            return $this->unsupported($table, $id, [
                'record' => ['table' => $table, 'id' => $id],
                'supported' => false,
            ]);
        }

        $row = DB::table($table)->where('id', $id)->first();

        if ($row === null) {
            // The record vanished between listing and classification. Fail closed.
            return $this->unsupported($table, $id, [
                'record' => ['table' => $table, 'id' => $id],
                'supported' => true,
                'row_found' => false,
            ]);
        }

        [$parentTable, $foreignKey] = self::CHAINS[$table];

        $evidence = [
            'record' => ['table' => $table, 'id' => $id],
            'existing_business_id' => $this->existingBusinessId($table, $row),
            'parent_table' => $parentTable,
            'foreign_key' => $foreignKey,
            'parent_id' => $row->{$foreignKey} ?? null,
            'warehouse_id' => null,
            'warehouse_business_id' => null,
            'outlet_id' => null,
            'outlet_business_id' => null,
            'parent_business_id' => null,
        ];

        $raw = $this->classifyChain($table, $id, $row, $evidence);

        $existing = $evidence['existing_business_id'];

        // Overlay persisted ownership (defensive; currently unreachable because
        // payables/receivables have no `business_id` column yet).
        if ($existing !== null) {
            $parentBusinessId = $raw['proposed'];
            $conflict = $parentBusinessId !== null && $parentBusinessId !== $existing;

            return new FinancialOwnershipResult(
                $table,
                $id,
                FinancialOwnershipResult::ALREADY_RESOLVED,
                $conflict ? self::R_ALREADY_CONFLICT : self::R_ALREADY,
                $conflict
                    ? "Record already has business_id {$existing}, but the parent chain proves {$parentBusinessId}; report the conflict, do not overwrite."
                    : "Record already has a persisted business_id ({$existing}); no change proposed.",
                $existing,
                $evidence,
            );
        }

        return new FinancialOwnershipResult(
            $table,
            $id,
            $raw['classification'],
            $raw['reason'],
            $raw['explanation'],
            $raw['proposed'],
            $evidence,
        );
    }

    /**
     * Walk the verified parent chain. Populates `$evidence` in place.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{classification: string, reason: string, explanation: string, proposed: ?int}
     */
    private function classifyChain(string $table, int $id, object $row, array &$evidence): array
    {
        [$parentTable, $foreignKey] = self::CHAINS[$table];

        if (! in_array($parentTable, self::WAREHOUSE_SCOPED_PARENTS, true)) {
            // Unsupported/unknown parent type: never treat it as ownership.
            return $this->raw(
                FinancialOwnershipResult::INVALID_OR_UNSUPPORTED_CHAIN,
                self::R_UNSUPPORTED,
                "Parent table {$parentTable} has no documented ownership chain; fail closed.",
            );
        }

        $parentId = $row->{$foreignKey} ?? null;

        if ($parentId === null) {
            return $this->raw(
                FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED,
                self::R_MANUAL_NO_PARENT,
                "No {$parentTable} parent ({$foreignKey} is null); ownership cannot be proven and requires manual assignment.",
            );
        }

        $parent = DB::table($parentTable)->where('id', $parentId)->first();

        if ($parent === null) {
            return $this->raw(
                FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT,
                self::R_DANGLING_PARENT,
                "Parent {$parentTable}#{$parentId} does not exist; requires manual reconciliation.",
            );
        }

        $warehouseId = $parent->warehouse_id ?? null;
        $evidence['warehouse_id'] = $warehouseId;

        if ($warehouseId === null) {
            return $this->raw(
                FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP,
                self::R_AMBIGUOUS_PARENT_WITHOUT_WAREHOUSE,
                "Parent {$parentTable}#{$parentId} has no warehouse_id; the ownership chain is incomplete.",
            );
        }

        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();

        if ($warehouse === null) {
            return $this->raw(
                FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT,
                self::R_DANGLING_WAREHOUSE,
                "Warehouse#{$warehouseId} referenced by {$parentTable}#{$parentId} does not exist; requires manual reconciliation.",
            );
        }

        $warehouseBusinessId = $warehouse->business_id ?? null;
        $outletId = $warehouse->outlet_id ?? null;
        $evidence['warehouse_business_id'] = $warehouseBusinessId;
        $evidence['outlet_id'] = $outletId;

        $outletBusinessId = null;

        if ($outletId !== null) {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();

            if ($outlet === null) {
                return $this->raw(
                    FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT,
                    self::R_DANGLING_OUTLET,
                    "Outlet#{$outletId} referenced by warehouse#{$warehouseId} does not exist; requires manual reconciliation.",
                );
            }

            $outletBusinessId = $outlet->business_id ?? null;
            $evidence['outlet_business_id'] = $outletBusinessId;
        }

        // Conflicting business signals: the two persisted sources disagree.
        if ($warehouseBusinessId !== null
            && $outletBusinessId !== null
            && (int) $warehouseBusinessId !== (int) $outletBusinessId) {
            return $this->raw(
                FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP,
                self::R_AMBIGUOUS_CONFLICT,
                "Warehouse#{$warehouseId} business_id ({$warehouseBusinessId}) conflicts with outlet#{$outletId} business_id ({$outletBusinessId}); fail closed.",
            );
        }

        $businessId = $warehouseBusinessId ?? $outletBusinessId;

        if ($businessId === null) {
            return $this->raw(
                FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP,
                self::R_AMBIGUOUS_UNOWNED_WAREHOUSE,
                "Warehouse#{$warehouseId} has neither a business_id nor an owned outlet; ownership cannot be proven.",
            );
        }

        $evidence['parent_business_id'] = (int) $businessId;

        $via = $warehouseBusinessId !== null ? 'warehouse.business_id' : 'outlet.business_id';

        return $this->raw(
            FinancialOwnershipResult::AUTO_RESOLVABLE,
            $warehouseBusinessId !== null ? self::R_AUTO_WAREHOUSE : self::R_AUTO_OUTLET,
            "Proven via {$table}#{$id} -> {$parentTable}#{$parentId} -> warehouse#{$warehouseId} -> business#{$businessId} ({$via}).",
            (int) $businessId,
        );
    }

    /**
     * @return array{classification: string, reason: string, explanation: string, proposed: ?int}
     */
    private function raw(string $classification, string $reason, string $explanation, ?int $proposed = null): array
    {
        return [
            'classification' => $classification,
            'reason' => $reason,
            'explanation' => $explanation,
            'proposed' => $proposed,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function unsupported(string $table, int $id, array $evidence): FinancialOwnershipResult
    {
        return new FinancialOwnershipResult(
            $table,
            $id,
            FinancialOwnershipResult::INVALID_OR_UNSUPPORTED_CHAIN,
            self::R_UNSUPPORTED,
            "No documented ownership chain for '{$table}'; fail closed.",
            null,
            $evidence,
        );
    }

    private function existingBusinessId(string $table, object $row): ?int
    {
        if (! array_key_exists($table, $this->hasBusinessIdColumn)) {
            $this->hasBusinessIdColumn[$table] = Schema::hasColumn($table, 'business_id');
        }

        if (! $this->hasBusinessIdColumn[$table]) {
            return null;
        }

        $value = $row->business_id ?? null;

        return $value === null ? null : (int) $value;
    }
}
