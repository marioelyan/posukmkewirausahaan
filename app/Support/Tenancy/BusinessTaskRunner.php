<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Services\BusinessContext;

/**
 * SCHED-01 / SCHED-02 — per-business execution harness for scheduled work.
 *
 * Scheduled commands run with no HTTP request and therefore no business
 * context. The tenant-scoped master data they iterate (`BelongsToBusiness`)
 * fails closed without a context, so a global run would silently process
 * nothing. This runner enumerates the active businesses and executes the task
 * once per business with an explicit context, always clearing the context in
 * a `finally` so a leak can never carry a business into the next task or run.
 *
 * Light work runs inline (no queue): shared hosting (open-decision Q8) has no
 * background worker, and these operations (reorder, segment sync, reminder
 * generation) are bounded and cheap per business.
 *
 * When `multi_business.enabled` is false the task runs exactly once with an
 * empty context, preserving the legacy global behaviour byte-for-byte.
 */
class BusinessTaskRunner
{
    public function __construct(private readonly BusinessContext $context) {}

    /**
     * @param  callable(?Business $business): void  $task
     */
    public function runForEachActiveBusiness(callable $task): void
    {
        if (! config('multi_business.enabled')) {
            $task(null);

            return;
        }

        $businesses = Business::query()
            ->where('status', Business::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        foreach ($businesses as $business) {
            try {
                $this->context->setActive($business);
                $task($business);
            } finally {
                // The context must always be cleared, even on exceptions, so a
                // failed business can never leak its scope into the next one.
                $this->context->clear();
            }
        }
    }
}
