<?php

namespace App\Console\Commands;

use App\Services\CustomerSegmentationService;
use App\Support\Tenancy\BusinessTaskRunner;
use Illuminate\Console\Command;

class CrmSyncSegmentsCommand extends Command
{
    protected $signature = 'crm:sync-segments';

    protected $description = 'Refresh auto customer segment memberships';

    public function handle(CustomerSegmentationService $segmentationService, BusinessTaskRunner $runner): int
    {
        // SCHED-01/02: refresh segments per active business with an explicit
        // context so memberships never leak across businesses. Flag off = one
        // legacy global pass.
        $runner->runForEachActiveBusiness(function () use ($segmentationService) {
            $segmentationService->syncAutoSegments();
        });

        $this->info('CRM auto segments synced.');

        return self::SUCCESS;
    }
}
