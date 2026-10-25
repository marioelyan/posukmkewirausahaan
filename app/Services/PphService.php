<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\Warehouse;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Fase 1 slice 3: PPh Final UMKM 0,5% (spec §3.10, locked Q4 = manual
 * monthly action). PP 55/2022.
 *
 * Omset basis mirrors the lite report and the sheet: SUM(grand_total) of
 * completed (paid) transactions in the month — already net of discounts.
 * Transaction-based (not journal-based) on purpose: it covers every paid
 * sale, including bank/QRIS/split-tender sales that slice 1 does not
 * journal yet. Unpaid credit sales enter omset when they are paid, the
 * same timing the lite revenue uses.
 *
 * Business scoping: transactions carry no business_id, so a business
 * omset counts paid transactions whose shift belongs to the business, or
 * (for shiftless transactions) whose warehouse belongs to it. A null
 * business means the whole installation (legacy single-tenant installs).
 */
class PphService
{
    public const RATE = 0.005;

    public function __construct(
        private readonly JournalService $journalService,
    ) {}

    /**
     * Validate a YYYY-MM period string. Carbon::createFromFormat rolls
     * '2026-13' into the next year, so the regex guard is the real check.
     */
    public function validatePeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Invalid PPh period '{$period}'; expected YYYY-MM.");
        }
    }

    public function omsetForMonth(?int $businessId, string $period): int
    {
        $this->validatePeriod($period);

        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        $query = Transaction::query()
            ->completed()
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()]);

        if ($businessId !== null) {
            $shiftIds = CashierShift::query()->where('business_id', $businessId)->pluck('id');
            $warehouseIds = Warehouse::query()->where('business_id', $businessId)->pluck('id');

            $query->where(function ($builder) use ($shiftIds, $warehouseIds) {
                $builder->whereIn('cashier_shift_id', $shiftIds)
                    ->orWhereIn('warehouse_id', $warehouseIds);
            });
        }

        return (int) $query->sum('grand_total');
    }

    public function taxForMonth(?int $businessId, string $period): int
    {
        return (int) round($this->omsetForMonth($businessId, $period) * self::RATE);
    }

    /**
     * Post the monthly accrual (Dr 5300 / Cr 2120). Returns null when no
     * tax is due (zero omset); a duplicate month is rejected by
     * JournalService unless the first entry was reversed.
     */
    public function accrue(?int $businessId, string $period, ?int $userId): ?JournalEntry
    {
        $omset = $this->omsetForMonth($businessId, $period);
        $tax = (int) round($omset * self::RATE);

        return $this->journalService->postPphAccrual($businessId, $period, $omset, $tax, $userId);
    }

    /**
     * The live (non-reversed) accrual for a month, or null.
     */
    public function liveAccrual(?int $businessId, string $period): ?JournalEntry
    {
        $this->validatePeriod($period);

        return $this->journalService->livePphEntry($businessId, JournalService::SOURCE_PPH_PERIOD, $period);
    }

    /**
     * Post the payment journal for a month's accrual (Dr 2120 / Cr 1100
     * cash or 1110 bank). Full payment only: the amount must equal the
     * live accrual's tax, otherwise the payment is rejected.
     */
    public function pay(?int $businessId, string $period, string $method, ?int $userId): JournalEntry
    {
        $accrual = $this->liveAccrual($businessId, $period);

        if ($accrual === null) {
            throw new InvalidArgumentException(
                "No live PPh accrual for {$period}; accrue it first before paying."
            );
        }

        $accrual->loadMissing('lines.account');

        $amount = (int) $accrual->lines
            ->filter(fn ($line) => $line->account?->code === '5300')
            ->sum('debit');

        if ($amount <= 0) {
            throw new InvalidArgumentException("PPh accrual for {$period} has no tax amount to pay.");
        }

        return $this->journalService->postPphPayment($businessId, $period, $amount, $method, $userId);
    }
}
