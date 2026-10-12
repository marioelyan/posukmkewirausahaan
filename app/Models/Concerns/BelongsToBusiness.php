<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in business ownership for master-data models.
 *
 * When `multi_business.enabled` is true AND an active business context exists,
 * reads are transparently scoped to that business and new records inherit the
 * business id from the server-side context (never from request payloads).
 *
 * When the flag is off the trait is inert, preserving legacy behaviour.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $builder) {
            if (! config('multi_business.enabled')) {
                return;
            }

            $context = app(BusinessContext::class);

            if ($context->hasActiveBusiness()) {
                $builder->where(
                    $builder->getModel()->getTable().'.business_id',
                    $context->businessId(),
                );

                return;
            }

            // Tenancy is enabled but there is no verified business context. Fail
            // closed so a tenant-scoped query can never silently degrade into an
            // unrestricted global query. Callers that legitimately need cross
            // business/system access must opt in explicitly (e.g. background jobs
            // iterate per business and set the context, or call withoutGlobalScope).
            $builder->whereRaw('1 = 0');
        });

        static::creating(function ($model) {
            if (! config('multi_business.enabled')) {
                return;
            }

            $context = app(BusinessContext::class);

            if ($context->hasActiveBusiness()) {
                // Server context wins; a request body can never move ownership.
                $model->business_id = $context->businessId();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->getTable().'.business_id', $businessId);
    }
}
