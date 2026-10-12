<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\Tenancy\BusinessMembershipProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BusinessMembershipBackfillCommand extends Command
{
    protected $signature = 'business:membership-backfill
        {--apply : Write memberships (default is a read-only dry-run)}
        {--business= : Target a single business id}
        {--confirm-membership-backfill : Required with --apply; acknowledge that outlet access becomes a business membership}';

    protected $description = 'Provision business memberships from existing user outlet assignments';

    public function handle(BusinessMembershipProvisioner $provisioner): int
    {
        if (! Schema::hasTable('businesses') || ! Schema::hasTable('business_user')) {
            $this->error('The tenancy tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $target = null;

        if ($this->option('business')) {
            $target = Business::find((int) $this->option('business'));

            if (! $target) {
                $this->error('Business #'.$this->option('business').' was not found.');

                return self::FAILURE;
            }
        } elseif (Business::count() === 0) {
            $this->warn('No business exists yet. Run `business:backfill --create-default --apply --confirm-legacy-ownership` first.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->option('confirm-membership-backfill')) {
            $this->error('Refusing to apply without --confirm-membership-backfill.');

            return self::FAILURE;
        }

        $plan = $provisioner->plan($target);

        if ($plan->isEmpty()) {
            $this->info('Nothing to provision (no users are assigned to business outlets).');

            return self::SUCCESS;
        }

        $this->table(
            ['User', 'Email', 'Business', 'Role', 'Action'],
            $plan->map(fn (array $row) => [
                '#'.$row['user_id'].' '.$row['name'],
                $row['email'],
                '#'.$row['business_id'].' '.$row['business'],
                $row['role'] ?? '-',
                $row['action'],
            ])->values()->all(),
        );

        $skipped = $plan->where('action', 'skip')->count();

        if ($skipped > 0) {
            $this->line("{$skipped} user(s) have no mappable platform role and will be skipped; assign them manually.");
        }

        if (! $apply) {
            $this->info('Dry-run only. No data was written. Re-run with --apply --confirm-membership-backfill to write.');

            return self::SUCCESS;
        }

        $result = $provisioner->apply($target);

        $this->info("Memberships created: {$result['created']}; already present: {$result['skipped']}; skipped (unmapped): {$result['unmapped']}.");

        return self::SUCCESS;
    }
}
