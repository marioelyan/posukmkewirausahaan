<?php

namespace App\Console\Commands;

use App\Services\CrmAutomationService;
use Illuminate\Console\Command;

class CrmGenerateRemindersCommand extends Command
{
    protected $signature = 'crm:generate-reminders';

    protected $description = 'Generate CRM reminder campaigns for due soon, overdue, and repeat order';

    public function handle(CrmAutomationService $crmAutomationService): int
    {
        $crmAutomationService->generateScheduledReminders();

        $this->info('CRM reminders generated.');

        return self::SUCCESS;
    }
}
