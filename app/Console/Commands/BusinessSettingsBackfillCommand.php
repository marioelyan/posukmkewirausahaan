<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SETTINGS-01 backfill (Q5 / ADR-04 §7).
 *
 * Read-only by default. The only unambiguous automatic mapping is
 * outlet-scoped rows (`outlet_id` set) which inherit the outlet's business.
 * Global tenant-owned rows are reported as inherited defaults and are never
 * reassigned unless an operator explicitly targets a business.
 */
class BusinessSettingsBackfillCommand extends Command
{
    protected $signature = 'business:settings-backfill
        {--apply : Write the mapping (default is a read-only dry-run)}
        {--business= : Target business id for global tenant-owned rows}
        {--confirm-legacy-ownership : Required with --apply to move global tenant rows to --business}';

    protected $description = 'Audit and (optionally) backfill settings.business_id (outlet rows inherit their outlet business)';

    /**
     * Platform keys that must stay on the global row (ADR-04 SETTINGS-07).
     *
     * @var array<int, string>
     */
    private array $platformKeys = [
        'app_setup_completed',
        'setup_warehouse_id',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('settings')) {
            $this->error('The settings table is missing. Run migrations first.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('settings', 'business_id')) {
            $this->error('Column settings.business_id is missing. Run migrations first.');

            return self::FAILURE;
        }

        $outletRows = DB::table('settings')->whereNotNull('outlet_id')->whereNull('business_id');
        $outletPending = (clone $outletRows)->count();

        $globalTenantRows = DB::table('settings')
            ->whereNull('outlet_id')
            ->whereNull('business_id')
            ->whereNotIn('key', $this->platformKeys);
        $globalPending = (clone $globalTenantRows)->count();

        $already = DB::table('settings')->whereNotNull('business_id')->count();
        $platform = DB::table('settings')
            ->whereNull('business_id')
            ->whereIn('key', $this->platformKeys)
            ->count();

        $this->table(
            ['Bucket', 'Rows'],
            [
                ['Already business-scoped', $already],
                ['Outlet rows to inherit (unambiguous)', $outletPending],
                ['Global tenant rows (inherited default)', $globalPending],
                ['Platform global keys (kept global)', $platform],
            ],
        );

        $target = null;

        if ($this->option('business')) {
            $target = Business::find((int) $this->option('business'));

            if (! $target) {
                $this->error('Business #'.$this->option('business').' was not found.');

                return self::FAILURE;
            }
        }

        if (! $this->option('apply')) {
            $this->info('Dry-run only. No data was written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        if ($globalPending > 0 && $target === null) {
            $this->warn('Global tenant rows were left as inherited defaults (no --business given).');
        }

        if ($globalPending > 0 && $target !== null && ! $this->option('confirm-legacy-ownership')) {
            $this->error('Refusing to move global tenant rows without --confirm-legacy-ownership.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($target) {
            // Outlet rows: inherit the outlet's business (unambiguous).
            DB::table('settings')
                ->whereNotNull('outlet_id')
                ->whereNull('business_id')
                ->update([
                    'business_id' => DB::raw('(select business_id from outlets where outlets.id = settings.outlet_id)'),
                ]);

            if ($target !== null) {
                DB::table('settings')
                    ->whereNull('outlet_id')
                    ->whereNull('business_id')
                    ->whereNotIn('key', $this->platformKeys)
                    ->update(['business_id' => $target->id]);
            }
        });

        $this->info('Backfill applied. Re-running with --apply is now idempotent.');

        return self::SUCCESS;
    }
}
