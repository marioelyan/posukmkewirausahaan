<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReorderService;
use App\Support\Tenancy\BusinessTaskRunner;
use Illuminate\Console\Command;

class GenerateReorderCommand extends Command
{
    protected $signature = 'reorder:generate';

    protected $description = 'Create draft purchase orders from low-stock reorder points';

    public function handle(ReorderService $reorderService, BusinessTaskRunner $runner): int
    {
        // The PO author is a platform super-admin; per-warehouse assignment can
        // be refined later if the business model needs it.
        $userId = User::role('super-admin')->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');

        $ran = false;
        $created = 0;
        $hadLowStock = false;

        // SCHED-01/02: iterate each active business with an explicit context so
        // the tenant-scoped warehouse queries never collapse to a global (or
        // fail-closed) run. Flag off = one legacy pass over everything.
        $runner->runForEachActiveBusiness(function (?Business $business) use ($reorderService, $userId, &$ran, &$created, &$hadLowStock) {
            $ran = true;

            $warehouses = Warehouse::where('is_active', true)->orderBy('id')->get();

            if ($warehouses->isEmpty()) {
                return;
            }

            foreach ($warehouses as $warehouse) {
                $products = $reorderService->getLowStockProducts($warehouse->id);

                if ($products->isEmpty()) {
                    continue;
                }

                $hadLowStock = true;

                $order = $reorderService->createDraftPurchaseOrder($products, $userId, $warehouse->id);

                if (! $order) {
                    continue;
                }

                $created++;
                $this->info("Draft purchase order #{$order->id} created for {$warehouse->name} with {$order->items->count()} item(s).");
            }
        });

        if (! $ran) {
            $this->info('No active warehouses.');

            return self::SUCCESS;
        }

        if ($created === 0) {
            $this->info($hadLowStock
                ? 'Low-stock products found, but no reorder quantity to suggest.'
                : 'No low-stock products.');
        }

        return self::SUCCESS;
    }
}
