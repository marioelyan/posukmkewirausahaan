<?php

namespace App\Support\Tenancy;

use App\Services\BusinessContext;
use App\Services\FinancialOwnershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01/05 authoritative read filter for the financial ledger.
 *
 * Active only while `multi_business.enabled` is true. When active, model queries
 * against payables/receivables see ONLY:
 *
 *   1. records owned by the active business with `ownership_status = 'resolved'`
 *      (authoritative post-backfill ownership), OR
 *   2. records that were never evaluated (`unassigned`) — the FIN-01 transition
 *      window, where the caller's chain filtering (service scopes +
 *      TenantOwnership) remains the boundary, exactly as in phase 4E.
 *
 * Everything evaluated but NOT resolved (manual_required / ambiguous / dangling /
 * unsupported) is quarantined and excluded even when the parent chain would
 * prove ownership (design §2.2/§2.3). The active-business filter applies only
 * when a context is resolved (web middleware); without one the scope degrades to
 * the phase-4E transition behaviour (unassigned rows only), never leaking
 * resolved records of other businesses.
 *
 * When multi-business is disabled (legacy single-tenant mode) this scope is a
 * strict no-op and behaviour is unchanged.
 */
class FinancialOwnershipScope implements Scope
{
    private static ?bool $columnsPresent = null;

    public function apply(Builder $builder, Model $model): void
    {
        if (! (bool) config('multi_business.enabled')) {
            return;
        }

        if (self::$columnsPresent === null) {
            self::$columnsPresent = Schema::hasColumn($model->getTable(), 'business_id')
                && Schema::hasColumn($model->getTable(), 'ownership_status');
        }

        if (! self::$columnsPresent) {
            // Flag enabled but migrations not applied — behave as legacy rather
            // than erroring on missing columns.
            return;
        }

        $businessId = app(BusinessContext::class)->businessId();

        $builder->where(function (Builder $q) use ($businessId) {
            // Quarantine barrier (FIN-01/05): rows that were evaluated but are
            // NOT 'resolved' (manual_required / ambiguous / dangling /
            // unsupported) are always hidden — even when the parent chain or a
            // matching business_id would otherwise apply (design §2.2/§2.3).
            $q->where(function (Builder $sub) {
                $sub->whereNull('ownership_status')
                    ->orWhere('ownership_status', FinancialOwnershipService::STATUS_UNASSIGNED);
            });

            // Authoritative ownership — meaningful only when a business context
            // is active (web requests resolve it via middleware). Without a
            // context the unassigned transition window above is the only
            // visible set, exactly as in phase 4E (service scopes filter by
            // the caller's warehouse set).
            if ($businessId !== null) {
                $q->orWhere(function (Builder $sub) use ($businessId) {
                    $sub->where('business_id', $businessId)
                        ->where('ownership_status', FinancialOwnershipService::STATUS_RESOLVED);
                });
            }
        });
    }
}
