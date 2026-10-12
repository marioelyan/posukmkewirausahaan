<?php

namespace App\Console\Commands;

use App\Services\CrmAutomationService;
use App\Support\Tenancy\BusinessTaskRunner;
use Illuminate\Console\Command;

class CrmGenerateRemindersCommand extends Command
{
    protected $signature = 'crm:generate-reminders';

    protected $description = 'Generate CRM reminder campaigns for due soon, overdue, and repeat order';

    public function handle(CrmAutomationService $crmAutomationService, BusinessTaskRunner $runner): int
    {
        // SCHED-01/02: generate reminders per active business. The context key
        // is namespaced per business so campaign rows never collide or mix
        // audiences across businesses. Flag off = one legacy global pass.
        $runner->runForEachActiveBusiness(function () use ($crmAutomationService) {
            $crmAutomationService->generateScheduledReminders();
        });

        $this->info('CRM reminders generated.');

        return self::SUCCESS;
    }
}
