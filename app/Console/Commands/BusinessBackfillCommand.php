<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BusinessBackfillCommand extends Command
{
    protected $signature = 'business:backfill
        {--apply : Write the mapping (default is a read-only dry-run)}
        {--business= : Target an existing business id}
        {--create-default : Create a single default business when none exists}
        {--name= : Name for the created default business}
        {--confirm-legacy-ownership : Required with --apply; acknowledge that every unassigned legacy row becomes owned by the target business}';

    protected $description = 'Audit and (optionally) backfill business_id for master data, outlets, and warehouses';

    /**
     * Master-data tables that are owned directly by a business.
     *
     * @var array<int, string>
     */
    private array $businessOwned = [
        'products',
        'categories',
        'customers',
        'suppliers',
        'units',
        'price_lists',
        'customer_segments',
        'customer_campaigns',
        'customer_vouchers',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('businesses')) {
            $this->error('The businesses table is missing. Run migrations first.');

            return self::FAILURE;
        }

        foreach (array_merge($this->businessOwned, ['outlets', 'warehouses']) as $table) {
            if (! Schema::hasColumn($table, 'business_id')) {
                $this->error("Column {$table}.business_id is missing. Run migrations first.");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');
        $count = Business::count();

        $target = null;
        $wouldCreateName = null;

        if ($this->option('business')) {
            $target = Business::find((int) $this->option('business'));
            if (! $target) {
                $this->error('Business #'.$this->option('business').' was not found.');

                return self::FAILURE;
            }
        } elseif ($count === 0) {
            if (! $this->option('create-default')) {
                $this->warn('No business exists yet. Re-run with --create-default (and --apply) to create one.');

                return self::SUCCESS;
            }

            $wouldCreateName = $this->option('name')
                ?: Setting::get('store_name')
                ?: config('multi_business.default_name');
        } elseif ($count === 1) {
            $target = Business::first();
        } else {
            $this->error('Multiple businesses exist. Specify --business=ID; refusing to guess ownership.');

            return self::FAILURE;
        }

        $plan = $this->buildPlan($target?->id);

        if ($wouldCreateName !== null) {
            $this->info("DRY-RUN: would create default business \"{$wouldCreateName}\".");
        } elseif ($target) {
            $this->info("Target business: #{$target->id} {$target->name}");
        }

        $this->table(
            ['Table', 'Pending (null)', 'Already mapped', 'Conflict (other business)'],
            collect($plan)->map(fn (array $row, string $table) => [
                $table,
                $row['pending'],
                $row['already'],
                $row['conflict'],
            ])->values()->all(),
        );

        $this->line('Warehouses without any outlet (legacy, mapped to target): '.$this->legacyWarehouseCount());

        $conflicts = collect($plan)->sum('conflict');
        if ($conflicts > 0) {
            $this->error("Found {$conflicts} row(s) already owned by another business. Aborting; resolve manually.");

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry-run only. No data was written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        // Blanket mapping of every unassigned legacy row to one business is an
        // ownership decision (see multi-business-open-decisions Q7/Q9). Require an
        // explicit acknowledgement so it can never happen by accident.
        if (! $this->option('confirm-legacy-ownership')) {
            $this->error('Refusing to apply without --confirm-legacy-ownership. This would assign every unassigned legacy row to the target business.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($wouldCreateName, &$target) {
            if ($wouldCreateName !== null) {
                $target = Business::create([
                    'name' => $wouldCreateName,
                    'slug' => Str::slug($wouldCreateName).'-'.Str::lower(Str::random(6)),
                    'status' => Business::STATUS_ACTIVE,
                    'currency' => 'IDR',
                    'timezone' => config('app.timezone', 'Asia/Jakarta'),
                ]);
            }

            if (! $target) {
                return;
            }

            DB::table('outlets')
                ->whereNull('business_id')
                ->update(['business_id' => $target->id]);

            DB::table('warehouses')
                ->whereNull('business_id')
                ->update(['business_id' => $target->id]);

            foreach ($this->businessOwned as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                    continue;
                }

                DB::table($table)
                    ->whereNull('business_id')
                    ->update(['business_id' => $target->id]);
            }
        });

        if (! $target) {
            $this->error('No target business resolved; nothing to apply.');

            return self::FAILURE;
        }

        if ($wouldCreateName !== null) {
            $this->info("Created business #{$target->id} {$target->name}.");
        }

        $this->info('Backfill applied. Re-running with --apply is now idempotent (0 pending).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{pending: int, already: int, conflict: int}>
     */
    private function buildPlan(?int $businessId): array
    {
        $plan = [];

        foreach (array_merge($this->businessOwned, ['outlets', 'warehouses']) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            $query = DB::table($table);
            $pending = (clone $query)->whereNull('business_id')->count();
            $already = (clone $query)->whereNotNull('business_id')->count();
            $conflict = $businessId === null
                ? 0
                : (clone $query)->whereNotNull('business_id')->where('business_id', '!=', $businessId)->count();

            $plan[$table] = ['pending' => $pending, 'already' => $already, 'conflict' => $conflict];
        }

        return $plan;
    }

    private function legacyWarehouseCount(): int
    {
        return DB::table('warehouses')->whereNull('outlet_id')->count();
    }
}
