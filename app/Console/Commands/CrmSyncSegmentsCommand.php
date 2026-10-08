<?php

namespace App\Console\Commands;

use App\Services\CustomerSegmentationService;
use Illuminate\Console\Command;

class CrmSyncSegmentsCommand extends Command
{
    protected $signature = 'crm:sync-segments';

    protected $description = 'Refresh auto customer segment memberships';

    public function handle(CustomerSegmentationService $segmentationService): int
    {
        $segmentationService->syncAutoSegments();

        $this->info('CRM auto segments synced.');

        return self::SUCCESS;
    }
}
