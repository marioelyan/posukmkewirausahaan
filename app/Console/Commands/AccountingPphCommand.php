<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\PphService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Fase 1 slice 3: manual monthly PPh action (spec §3.10, locked Q4).
 *
 *   php artisan accounting:pph 2026-10
 *   php artisan accounting:pph 2026-10 --business=1
 *   php artisan accounting:pph 2026-10 --pay --method=transfer
 *
 * Accrual posts Dr 5300 / Cr 2120; --pay posts Dr 2120 / Cr 1100 (cash)
 * or 1110 (anything else). A second run for the same month is rejected
 * unless the first entry was reversed (see JournalService).
 */
class AccountingPphCommand extends Command
{
    protected $signature = 'accounting:pph
        {month : PPh period in YYYY-MM format}
        {--business= : Business ID (defaults to the only business when exactly one exists)}
        {--pay : Post the payment journal instead of the accrual}
        {--method=cash : Payment media for --pay (cash = Kas, anything else = Bank)}';

    protected $description = 'Post the monthly PPh Final UMKM 0,5% journal (accrual or payment)';

    public function handle(PphService $pphService): int
    {
        $period = (string) $this->argument('month');

        try {
            $businessId = $this->resolveBusinessId();
            $pphService->validatePeriod($period);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            if ($this->option('pay')) {
                $entry = $pphService->pay($businessId, $period, (string) $this->option('method'), null);
                $this->info("PPh payment journal #{$entry->id} posted for {$period}.");

                return self::SUCCESS;
            }

            $omset = $pphService->omsetForMonth($businessId, $period);
            $entry = $pphService->accrue($businessId, $period, null);

            if ($entry === null) {
                $this->info("No PPh due for {$period} (omset Rp".number_format($omset, 0, ',', '.').').');

                return self::SUCCESS;
            }

            $this->info("PPh accrual journal #{$entry->id} posted for {$period} (omset Rp".number_format($omset, 0, ',', '.').').');

            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveBusinessId(): ?int
    {
        $option = $this->option('business');

        if ($option !== null && $option !== '') {
            $business = Business::whereKey((int) $option)->first();

            if ($business === null) {
                throw new InvalidArgumentException("Business #{$option} not found.");
            }

            return $business->id;
        }

        $count = Business::count();

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return (int) Business::query()->value('id');
        }

        throw new InvalidArgumentException(
            'Multiple businesses exist; pass --business=<id> to select one.'
        );
    }
}
