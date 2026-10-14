<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\FinancialOwnershipResolver;
use App\Support\Tenancy\FinancialOwnershipResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FIN-01/02/05 — persisted financial ownership lifecycle.
 *
 * Ownership is written ONLY here and ONLY through two explicit verbs:
 *
 *  1. applyBackfill()   — validated automatic backfill (business:financial-ownership-backfill
 *                         with dual confirmation). Writes business_id ONLY when the
 *                         persisted parent chain proves exactly one business, in the same
 *                         transaction as the row update (lockForUpdate + per-row audit).
 *  2. assign()          — explicit manual assignment (business:financial-ownership-assign),
 *                         a "quarantine exit" for records that cannot be proven automatically.
 *
 * evaluateTable() writes the six-state ownership_status ONLY — it never guesses a
 * business. All other code paths must not write these columns directly.
 */
class FinancialOwnershipService
{
    public const STATUS_UNASSIGNED = 'unassigned';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_MANUAL_REQUIRED = 'manual_required';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_DANGLING = 'dangling';

    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var array<string, string> */
    private const STATUS_BY_CLASSIFICATION = [
        FinancialOwnershipResult::AUTO_RESOLVABLE => self::STATUS_UNASSIGNED,
        FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED => self::STATUS_MANUAL_REQUIRED,
        FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP => self::STATUS_AMBIGUOUS,
        FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT => self::STATUS_DANGLING,
        FinancialOwnershipResult::ALREADY_RESOLVED => self::STATUS_RESOLVED,
        FinancialOwnershipResult::INVALID_OR_UNSUPPORTED_CHAIN => self::STATUS_UNSUPPORTED,
    ];

    public function __construct(
        private readonly FinancialOwnershipResolver $resolver,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function statusFor(FinancialOwnershipResult $result): string
    {
        if ($result->reasonCode === FinancialOwnershipResolver::R_ALREADY_CONFLICT) {
            return self::STATUS_AMBIGUOUS;
        }

        return self::STATUS_BY_CLASSIFICATION[$result->classification] ?? self::STATUS_UNASSIGNED;
    }

    /**
     * Derive the owning business for a warehouse (mirrors the resolver chain).
     * Used by FIN-04 creation paths (checkout receivables); null = not provable.
     */
    public function businessIdForWarehouse(int $warehouseId): ?int
    {
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first(['id', 'business_id', 'outlet_id']);

        if ($warehouse === null) {
            return null;
        }

        $outletBusinessId = $warehouse->outlet_id !== null
            ? DB::table('outlets')->where('id', $warehouse->outlet_id)->value('business_id')
            : null;

        if ($warehouse->business_id !== null && $outletBusinessId !== null
            && (int) $warehouse->business_id !== (int) $outletBusinessId) {
            return null; // conflict — fail closed
        }

        $businessId = $warehouse->business_id ?? $outletBusinessId;

        return $businessId !== null ? (int) $businessId : null;
    }

    /**
     * FIN-04 stamp for a freshly created financial record whose parent is a
     * warehouse (checkout receivables). 'resolved' when provable, otherwise
     * left 'unassigned' (never a guess).
     *
     * @return array{business_id: int|null, ownership_status: string, ownership_evaluated_at: Carbon|null}
     */
    public function stampFromWarehouse(int $warehouseId): array
    {
        $businessId = $this->businessIdForWarehouse($warehouseId);

        return [
            'business_id' => $businessId,
            'ownership_status' => $businessId !== null ? self::STATUS_RESOLVED : self::STATUS_UNASSIGNED,
            'ownership_evaluated_at' => $businessId !== null ? now() : null,
        ];
    }

    public function newRunId(): string
    {
        return 'fin-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6));
    }

    /**
     * FIN-01 step 4: write ownership_status ONLY (never business_id).
     * AUTO_RESOLVABLE rows stay 'unassigned' until the validated apply step.
     *
     * @return array<string, int> status => count
     */
    public function evaluateTable(string $table, int $chunk = 500, ?int $limit = null): array
    {
        $this->resolver->isSupported($table) || throw new \InvalidArgumentException("Unsupported financial table: {$table}");

        $counts = [];

        $processor = function (iterable $rows) use ($table, &$counts): void {
            foreach ($rows as $row) {
                $result = $this->resolver->resolve($table, (int) $row->id);

                $status = $result->isAutoResolvable()
                    ? self::STATUS_UNASSIGNED
                    : $this->statusFor($result);

                DB::table($table)->where('id', $row->id)->update([
                    'ownership_status' => $status,
                    'ownership_evaluated_at' => now(),
                ]);

                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        };

        if ($limit !== null) {
            $ids = DB::table($table)->orderBy('id')->limit($limit)->pluck('id')->all();
            $processor(DB::table($table)->whereIn('id', $ids)->orderBy('id')->get());

            return $counts;
        }

        DB::table($table)->orderBy('id')->chunkById($chunk, $processor);

        return $counts;
    }

    /**
     * FIN-01 apply step: validated automatic backfill. For every row, in an
     * exclusive transaction (lockForUpdate), re-resolve and then:
     *
     *  - business_id NULL + AUTO_RESOLVABLE → write business_id + 'resolved' (+ audit)
     *  - business_id NULL + non-auto        → write status only (quarantine reason)
     *  - business_id set (idempotent)       → refresh status; persisted-vs-chain
     *                                         conflict flips to 'ambiguous' (D3/D5 surface)
     *
     * @return array<string, mixed>
     */
    public function applyBackfill(string $table, int $chunk = 500, ?int $limit = null, ?string $runId = null): array
    {
        $this->resolver->isSupported($table) || throw new \InvalidArgumentException("Unsupported financial table: {$table}");

        $stats = [
            'run_id' => $runId ?: $this->newRunId(),
            'table' => $table,
            'written' => 0,
            'skipped_already_owned' => 0,
            'skipped_requires_manual' => 0,
            'conflicts_for_review' => 0,
            'statuses' => [],
        ];

        $process = function (iterable $rows) use ($table, &$stats): void {
            DB::transaction(function () use ($table, $rows, &$stats) {
                $ids = collect($rows)->pluck('id')->all();
                $locked = DB::table($table)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

                foreach ($locked as $row) {
                    $this->applyBackfillRow($table, $row, $stats['run_id'], $stats);
                }
            });
        };

        if ($limit !== null) {
            $ids = DB::table($table)->orderBy('id')->whereNull('business_id')->limit($limit)->pluck('id')->all();
            $process(DB::table($table)->whereIn('id', $ids)->orderBy('id')->get());

            return $stats;
        }

        DB::table($table)->orderBy('id')->chunkById($chunk, $process);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function applyBackfillRow(string $table, object $row, string $runId, array &$stats): void
    {
        $result = $this->resolver->resolve($table, (int) $row->id);

        if ($row->business_id !== null) {
            $status = $result->reasonCode === FinancialOwnershipResolver::R_ALREADY_CONFLICT
                ? self::STATUS_AMBIGUOUS
                : self::STATUS_RESOLVED;

            if ($status === self::STATUS_AMBIGUOUS) {
                $stats['conflicts_for_review']++;
            }

            if ((string) $row->ownership_status !== $status) {
                DB::table($table)->where('id', $row->id)->update([
                    'ownership_status' => $status,
                    'ownership_evaluated_at' => now(),
                ]);
                $stats['statuses'][$status] = ($stats['statuses'][$status] ?? 0) + 1;
            }

            $stats['skipped_already_owned']++;

            return;
        }

        if (! $result->isAutoResolvable() || $result->proposedBusinessId === null) {
            $status = $this->statusFor($result);

            DB::table($table)->where('id', $row->id)->update([
                'ownership_status' => $status,
                'ownership_evaluated_at' => now(),
            ]);

            $stats['statuses'][$status] = ($stats['statuses'][$status] ?? 0) + 1;
            $stats['skipped_requires_manual']++;

            if ($status === self::STATUS_AMBIGUOUS) {
                $stats['conflicts_for_review']++;
            }

            return;
        }

        DB::table($table)->where('id', $row->id)->update([
            'business_id' => $result->proposedBusinessId,
            'ownership_status' => self::STATUS_RESOLVED,
            'ownership_evaluated_at' => now(),
            'ownership_note' => null,
        ]);

        $stats['written']++;

        $this->auditLogService->log(
            event: 'financial.ownership_backfilled',
            module: 'finance',
            auditable: null,
            description: "Backfill FIN-01: {$table}#{$row->id} → business #{$result->proposedBusinessId}.",
            before: ['business_id' => null],
            after: ['business_id' => $result->proposedBusinessId, 'ownership_status' => self::STATUS_RESOLVED],
            meta: [
                'run_id' => $runId,
                'table' => $table,
                'id' => (int) $row->id,
                'reason_code' => $result->reasonCode,
                'evidence' => $result->evidence,
            ],
        );
    }

    /**
     * FIN-02 — explicit manual ownership assignment (quarantine exit). Fails
     * closed on every validation rule (design §3.2):
     *  - business must exist and be active,
     *  - record must exist on a supported table,
     *  - reassignment of a resolved record requires a reason,
     *  - assignment contradicting a provable chain requires explicit override.
     *
     * @return array{business_id: int, ownership_status: string}
     */
    public function assign(
        int $businessId,
        string $table,
        int $id,
        ?string $note = null,
        ?int $actorUserId = null,
        bool $overwriteConflict = false,
        ?string $reason = null,
    ): array {
        $this->resolver->isSupported($table) || throw new \InvalidArgumentException("Unsupported financial table: {$table}");

        $businessExists = Business::query()->whereKey($businessId)->where('status', 'active')->exists();

        if (! $businessExists) {
            throw new \InvalidArgumentException("Target business #{$businessId} does not exist or is not active.");
        }

        $row = DB::table($table)->where('id', $id)->first();

        if ($row === null) {
            throw new \InvalidArgumentException("{$table}#{$id} not found.");
        }

        $result = $this->resolver->resolve($table, $id);

        $isReassignment = $row->business_id !== null && (int) $row->business_id !== $businessId;
        $chainConflict = $result->proposedBusinessId !== null && (int) $result->proposedBusinessId !== $businessId;

        if ($isReassignment && blank($reason)) {
            throw new \InvalidArgumentException(
                "{$table}#{$id} is already owned by business #{$row->business_id}; reassignment requires a reason."
            );
        }

        if ($chainConflict && ! $overwriteConflict) {
            throw new \InvalidArgumentException(
                "Chain proves business #{$result->proposedBusinessId}; acknowledge the conflict (--override-conflict) to reassign."
            );
        }

        $before = ['business_id' => $row->business_id, 'ownership_status' => $row->ownership_status];

        DB::table($table)->where('id', $id)->update([
            'business_id' => $businessId,
            'ownership_status' => self::STATUS_RESOLVED,
            'ownership_evaluated_at' => now(),
            'ownership_note' => $note,
        ]);

        $actor = $actorUserId !== null ? User::find($actorUserId) : null;

        $this->auditLogService->log(
            event: 'financial.ownership_assigned',
            module: 'finance',
            auditable: null,
            description: "Manual assignment FIN-02: {$table}#{$id} → business #{$businessId}.",
            before: $before,
            after: ['business_id' => $businessId, 'ownership_status' => self::STATUS_RESOLVED],
            meta: [
                'table' => $table,
                'id' => $id,
                'note' => $note,
                'reason' => $reason,
                'override' => $chainConflict,
                'resolver_reason_code' => $result->reasonCode,
            ],
            actor: $actor,
        );

        return ['business_id' => $businessId, 'ownership_status' => self::STATUS_RESOLVED];
    }
}
