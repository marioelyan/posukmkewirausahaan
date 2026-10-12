<?php

namespace App\Support\Tenancy;

use App\Services\BusinessContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Builds a `unique` validation rule scoped to the verified active business.
 *
 * Duplicate detection must never reveal that another business already owns a
 * value. A plain `unique:customers,no_telp` query runs straight against the
 * database and bypasses Eloquent global scopes, so with tenancy enabled
 * Business A could probe Business B's customer phone numbers by observing
 * whether validation passes.
 *
 * When `multi_business.enabled` is true the check is constrained to the active
 * business (and fails closed without a context); when it is false the rule is
 * byte-for-byte equivalent to the legacy global uniqueness rule.
 */
class TenantUnique
{
    public static function for(
        string $table,
        string $column,
        ?int $ignoreId = null,
        ?string $ignoreColumn = null,
    ): Unique {
        $rule = Rule::unique($table, $column);

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId, $ignoreColumn);
        }

        if (! config('multi_business.enabled')) {
            return $rule;
        }

        $businessId = app(BusinessContext::class)->businessId();

        if ($businessId === null) {
            // The setup wizard runs before any business context exists. Allow
            // that unauthenticated bootstrap phase to keep the global rule;
            // authenticated requests must resolve a context (and fall closed)
            // so a member can never probe another business's values.
            if (request()->user() === null) {
                return $rule;
            }

            // Fail closed: without a verified context no row may satisfy the
            // uniqueness check, so cross-business probing is impossible.
            return $rule->where(fn ($query) => $query->whereRaw('1 = 0'));
        }

        return $rule->where(fn ($query) => $query->where('business_id', $businessId));
    }
}
