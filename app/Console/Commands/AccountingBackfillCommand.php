<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\AccountingBackfillService;
use Illuminate\Console\Command;

/**
 * Fase 1 slice 4 (spec §6 Q2): one-time backfill of journals for all
 * historical source documents.
 *
 *   php artisan accounting:backfill --dry-run   # preview, posts nothing
 *   php artisan accounting:backfill             # post + record completion
 *   php artisan accounting:backfill --force     # re-run after a recorded run
 *   php artisan accounting:backfill --business=1
 *
 * Re-runs never duplicate journals (one source = one entry). A second run
 * without --force is refused once a run has been recorded for the scope.
 * Skipped and failed sources are listed with reasons; nothing is silently
 * dropped.
 */
class AccountingBackfillCommand extends Command
{
    protected $signature = 'accounting:backfill
        {--business= : Business ID (default: all businesses)}
        {--dry-run : Preview counts without posting anything}
        {--force : Run even if a previous backfill was recorded for the scope}';

    protected $description = 'Backfill journals for all historical source documents (one-time, idempotent)';

    public function handle(AccountingBackfillService $backfill): int
    {
        $businessOption = $this->option('business');
        $businessId = $businessOption === null || $businessOption === '' ? null : (int) $businessOption;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($businessId !== null && Business::whereKey($businessId)->doesntExist()) {
            $this->error("Business #{$businessId} does not exist.");

            return self::FAILURE;
        }

        if (! $dryRun && ! $force && $backfill->alreadyCompleted($businessId)) {
            $this->error('A backfill has already been recorded for this scope. Re-run with --force to pick up sources that are still unjournaled (already-journaled sources are never posted twice).');

            return self::FAILURE;
        }

        $report = $backfill->run($businessId, $dryRun);

        $this->info($dryRun
            ? 'Backfill preview (dry run — nothing was posted, no marker written):'
            : 'Backfill complete:');

        $rows = [];

        foreach ($report['types'] as $type => $counts) {
            $rows[] = [
                $type,
                $counts['posted'],
                $counts['already'],
                array_sum($counts['skipped']),
                count($counts['failed']),
            ];
        }

        $this->table(['Source', 'Posted', 'Already', 'Skipped', 'Failed'], $rows);

        foreach ($report['types'] as $type => $counts) {
            if ($counts['skipped'] !== []) {
                $this->line("  {$type} skipped:");
                foreach ($counts['skipped'] as $reason => $count) {
                    $this->line("    - {$reason}: {$count}");
                }
            }

            foreach (array_slice($counts['failed'], 0, 20) as $failure) {
                $this->error("  {$type} #{$failure['id']} failed: {$failure['error']}");
            }

            if (count($counts['failed']) > 20) {
                $this->error('  ... and '.(count($counts['failed']) - 20).' more failures.');
            }
        }

        $verification = $report['verification'];

        $this->info('Verification:');
        $this->line('  Trial balance (debit - credit, all entries): Rp'.number_format($verification['trial_balance'], 0, ',', '.').($verification['trial_balance'] === 0 ? ' (OK)' : ' (NOT ZERO — investigate)'));

        if ($verification['reconciliation'] !== null) {
            $rec = $verification['reconciliation'];
            $this->line('  Journal P&L vs lite P&L:');
            $this->line('    - Journal pendapatan: Rp'.number_format($rec['journal']['pendapatan'], 0, ',', '.'));
            $this->line('    - Lite pendapatan: Rp'.number_format($rec['lite']['revenue'], 0, ',', '.'));
            $this->line('    - Penjualan belum dijurnal (bank/QRIS/split): Rp'.number_format($rec['bridges']['unjournaled_paid_sales'], 0, ',', '.'));
            $this->line('    - Piutang belum lunas (dijurnal, belum di lite): Rp'.number_format($rec['bridges']['unpaid_credit_sales'], 0, ',', '.'));
            $this->line('    - Retur (kontra 4110): Rp'.number_format($rec['bridges']['returns_contra'], 0, ',', '.'));
            $this->line('    - Residual pendapatan: Rp'.number_format($rec['residuals']['pendapatan'], 0, ',', '.'));
            $this->line('    - Residual HPP: Rp'.number_format($rec['residuals']['hpp'], 0, ',', '.').' (pembulatan per baris dapat menyisakan selisih kecil)');
            $this->line('    - Residual beban: Rp'.number_format($rec['residuals']['beban'], 0, ',', '.'));
            $this->line('    - Residual selisih kas: Rp'.number_format($rec['residuals']['selisih_kas'], 0, ',', '.'));
            $this->line('    - Residual bersih: Rp'.number_format($rec['residuals']['bersih'], 0, ',', '.').($rec['balanced'] ? ' (OK)' : ' (NOT BALANCED — investigate)'));
        } else {
            $this->line('  '.$verification['note']);
        }

        if ($verification['cash_proof'] !== null) {
            $cash = $verification['cash_proof'];
            $this->line('  Cash proof:');
            $this->line('    - Kas dari dokumen sumber: Rp'.number_format($cash['actual_cash'], 0, ',', '.'));
            $this->line('    - Saldo Kas jurnal (1100): Rp'.number_format($cash['journal_kas'], 0, ',', '.'));
            $this->line('    - Gap: Rp'.number_format($cash['gap'], 0, ',', '.'));
            $this->line('    - Gap dijelaskan (setoran tanpa tujuan, kind kosong, pelunasan tanpa hutang, beban gagal): Rp'.number_format($cash['explained_gap'], 0, ',', '.'));
            $this->line('    - Residual kas: Rp'.number_format($cash['residual'], 0, ',', '.').($cash['residual'] === 0 ? ' (OK)' : ' (NOT ZERO — investigate)'));
        }

        if (! $dryRun) {
            $backfill->markCompleted($businessId);
            $this->info('Backfill recorded for this scope. Re-run with --force to pick up newly fixable sources.');
        }

        $failedTotal = array_sum(array_map(fn ($counts) => count($counts['failed']), $report['types']));

        if ($failedTotal > 0) {
            $this->error("{$failedTotal} source(s) failed to journal. Fix the underlying data, then re-run with --force.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
