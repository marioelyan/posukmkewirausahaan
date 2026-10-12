<?php

namespace App\Support\Tenancy;

/**
 * Immutable outcome of {@see FinancialOwnershipResolver::resolve()} for a single
 * financial record.
 *
 * Implements the FIN-01 (Q7.1) classification vocabulary. A record is only
 * `AUTO_RESOLVABLE` when its complete, persisted parent chain proves exactly one
 * business. Everything else fails closed and requires manual handling.
 */
final class FinancialOwnershipResult
{
    /** The chain proves exactly one owning business. */
    public const AUTO_RESOLVABLE = 'AUTO_RESOLVABLE';

    /** No parent from which ownership can be proven (manual/standalone record). */
    public const MANUAL_ASSIGNMENT_REQUIRED = 'MANUAL_ASSIGNMENT_REQUIRED';

    /** Chain exists but does not resolve to exactly one business. */
    public const AMBIGUOUS_OWNERSHIP = 'AMBIGUOUS_OWNERSHIP';

    /** A required parent (or intermediate) row is missing / cannot be resolved. */
    public const DANGLING_OR_DELETED_PARENT = 'DANGLING_OR_DELETED_PARENT';

    /** Ownership is already persisted and validated (defensive; see notes). */
    public const ALREADY_RESOLVED = 'ALREADY_RESOLVED';

    /** The path cannot be evaluated safely (unsupported table/chain). */
    public const INVALID_OR_UNSUPPORTED_CHAIN = 'INVALID_OR_UNSUPPORTED_CHAIN';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $table,
        public readonly int $id,
        public readonly string $classification,
        public readonly string $reasonCode,
        public readonly string $explanation,
        public readonly ?int $proposedBusinessId,
        public readonly array $evidence,
    ) {}

    public function isAutoResolvable(): bool
    {
        return $this->classification === self::AUTO_RESOLVABLE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'id' => $this->id,
            'classification' => $this->classification,
            'reason_code' => $this->reasonCode,
            'explanation' => $this->explanation,
            'proposed_business_id' => $this->proposedBusinessId,
            'evidence' => $this->evidence,
        ];
    }
}
