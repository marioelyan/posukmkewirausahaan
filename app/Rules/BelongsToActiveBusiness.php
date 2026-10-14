<?php

namespace App\Rules;

use App\Support\Tenancy\TenantOwnership;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Verifies that the referenced record belongs to the active business.
 *
 * Unlike a bare `exists:table,id` rule (which bypasses Eloquent global scopes)
 * this closes the cross-business foreign-key gap even for tables that are not
 * tenant-scoped directly (warehouses resolve via their outlet).
 *
 * When multi-business is disabled the rule always passes, so legacy validation
 * keeps working unchanged. Nullable values must be handled by the caller's own
 * `nullable`/`sometimes` rules.
 */
class BelongsToActiveBusiness implements ValidationRule
{
    public function __construct(private string $table) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (app(TenantOwnership::class)->entityBelongsToActiveBusiness($this->table, $value)) {
            return;
        }

        $fail('The selected :attribute is invalid for the active business.');
    }
}
