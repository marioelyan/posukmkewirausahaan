<?php

namespace App\Console\Commands;

use App\Services\FinancialOwnershipService;
use Illuminate\Console\Command;

/**
 * FIN-02 — explicit manual ownership assignment (quarantine exit).
 *
 * Console workflow for records the chain cannot prove automatically
 * (manual_required / ambiguous / dangling), plus reviewed reassignments.
 * All validation lives in FinancialOwnershipService::assign() and fails
 * closed: inactive business, missing record, unreasoned reassignment, and
 * unacknowledged chain conflicts are all rejected.
 */
class FinancialOwnershipAssignCommand extends Command
{
    protected $signature = 'business:financial-ownership-assign
        {table : payables or receivables}
        {id : Record id}
        {business : Target business id (must exist and be active)}
        {--note= : Ownership note shown in the audit trail}
        {--reason= : Required when reassigning an already-resolved record}
        {--override-conflict : Acknowledge a resolver conflict and force the assignment}
        {--actor= : User id recorded as the assigner (default: system/CLI)}';

    protected $description = 'FIN-02: manually assign financial-record ownership to a business';

    public function handle(FinancialOwnershipService $service): int
    {
        $table = strtolower((string) $this->argument('table'));
        $id = (int) $this->argument('id');
        $businessId = (int) $this->argument('business');

        try {
            $result = $service->assign(
                businessId: $businessId,
                table: $table,
                id: $id,
                note: $this->option('note') ?: null,
                actorUserId: $this->option('actor') !== null ? (int) $this->option('actor') : null,
                overwriteConflict: (bool) $this->option('override-conflict'),
                reason: $this->option('reason') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Assigned {$table}#{$id} → business #{$businessId} ({$result['ownership_status']}).");

        return self::SUCCESS;
    }
}
