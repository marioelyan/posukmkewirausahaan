<?php

namespace App\Console\Commands;

use App\Services\FinancialOwnershipService;
use App\Support\Tenancy\FinancialOwnershipResolver;
use App\Support\Tenancy\FinancialOwnershipResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 — financial ownership backfill with explicit dual confirmation.
 *
 * Dry-run (default): read-only classification summary; writes NOTHING.
 * Apply (--apply + --confirm-financial-ownership): evaluates every row and
 * writes business_id ONLY where the persisted parent chain proves exactly one
 * business. Every write happens inside a transaction with lockForUpdate and a
 * per-row audit entry carrying the run_id (defect D6).
 *
 * Safe on an empty/legacy install and idempotent: already-owned rows are never
 * overwritten; a persisted-vs-chain conflict flips to 'ambiguous'.
 */
class FinancialOwnershipBackfillCommand extends Command
{
    protected $signature = 'business:financial-ownership-backfill
        {--apply : Write ownership (default is a read-only dry-run)}
        {--confirm-financial-ownership : Required with --apply; acknowledge per-row business_id writes on financial records}
        {--table=all : payables, receivables, or all}
        {--chunk=500 : Rows processed per chunk}
        {--limit= : Cap rows processed (staged rollout, ordered by id)}
        {--report= : Write a JSON run report to this path}';

    protected $description = 'FIN-01: evaluate financial-record ownership; with --apply write provable business_id (dual confirmation)';

    public function handle(FinancialOwnershipService $service, FinancialOwnershipResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $confirm = (bool) $this->option('confirm-financial-ownership');

        if ($apply && ! $confirm) {
            $this->error('--apply requires --confirm-financial-ownership. '
                .'This writes business_id on live financial records; acknowledge it explicitly.');

            return self::FAILURE;
        }

        $tables = $this->resolveTables($resolver);

        if ($tables === null) {
            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $reportPath = $this->option('report');

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $apply ? 'apply' : 'dry-run',
            'provenance' => [
                'driver' => DB::connection()->getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
                'app_env' => app()->environment(),
                'app_version' => config('app.version', 'unknown'),
                'multi_business_enabled' => (bool) config('multi_business.enabled'),
                'generated_by' => 'business:financial-ownership-backfill',
                'arguments' => [
                    'table' => (string) $this->option('table'),
                    'chunk' => $chunk,
                    'limit' => $limit,
                ],
            ],
            'tables' => [],
        ];

        $exit = self::SUCCESS;

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $report['tables'][$table] = ['present' => false];
                $this->warn("[{$table}] table not present; skipped.");

                continue;
            }

            if ($apply) {
                $stats = $service->applyBackfill($table, $chunk, $limit);
                $report['tables'][$table] = $stats;
                $this->renderApplyStats($stats);
            } else {
                $report['tables'][$table] = $this->dryRunCounts($resolver, $table, $chunk, $limit);
                $this->renderDryRunStats($table, $report['tables'][$table]);
            }

            $needsReview = $apply
                ? ($report['tables'][$table]['conflicts_for_review'] ?? 0) > 0
                : ($report['tables'][$table]['ambiguous'] ?? 0) > 0;

            if ($needsReview) {
                $exit = self::FAILURE;
            }
        }

        if ($reportPath !== null) {
            $bytes = file_put_contents(
                $reportPath,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );

            if ($bytes === false) {
                $this->error("Could not write report to '{$reportPath}'.");

                return self::FAILURE;
            }

            $this->line("Run report written to {$reportPath}");
        }

        if ($exit === self::FAILURE) {
            $this->warn('One or more records fall into a category that requires manual review '
                .'(ambiguous/conflict). Runs completed and wrote provable rows, but the '
                .'remaining quarantine needs FIN-02 manual assignment.');
        }

        return $exit;
    }

    /**
     * @return array<int, string>|null
     */
    private function resolveTables(FinancialOwnershipResolver $resolver): ?array
    {
        $requested = strtolower((string) $this->option('table'));

        if ($requested === 'all') {
            return $resolver->supportedTables();
        }

        if (! $resolver->isSupported($requested)) {
            $this->error("Unsupported --table '{$requested}'. Supported: "
                .implode(', ', $resolver->supportedTables()).', all.');

            return null;
        }

        return [$requested];
    }

    /**
     * Read-only classification counts for the dry-run (never writes).
     *
     * @return array<string, int>
     */
    private function dryRunCounts(FinancialOwnershipResolver $resolver, string $table, int $chunk, ?int $limit): array
    {
        $counts = [
            'present' => true,
            'total' => 0,
            'auto_resolvable' => 0,
            'manual_required' => 0,
            'ambiguous' => 0,
            'dangling' => 0,
            'unsupported' => 0,
            'already_resolved' => 0,
        ];

        DB::table($table)->orderBy('id')->chunkById($chunk, function ($rows) use ($resolver, $table, $limit, &$counts) {
            foreach ($rows as $row) {
                if ($limit !== null && $counts['total'] >= $limit) {
                    return false;
                }

                $counts['total']++;

                $classification = $resolver->resolve($table, (int) $row->id)->classification;

                $counts[match ($classification) {
                    FinancialOwnershipResult::AUTO_RESOLVABLE => 'auto_resolvable',
                    FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED => 'manual_required',
                    FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP => 'ambiguous',
                    FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT => 'dangling',
                    FinancialOwnershipResult::INVALID_OR_UNSUPPORTED_CHAIN => 'unsupported',
                    FinancialOwnershipResult::ALREADY_RESOLVED => 'already_resolved',
                    default => 'unsupported',
                }]++;
            }
        });

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function renderApplyStats(array $stats): void
    {
        $this->info("[{$stats['table']}] apply run {$stats['run_id']}");
        $this->table(
            ['Written (business_id)', 'Skipped (already owned)', 'Skipped (manual)', 'Conflicts for review'],
            [[$stats['written'], $stats['skipped_already_owned'], $stats['skipped_requires_manual'], $stats['conflicts_for_review']]],
        );

        if ($stats['statuses'] !== []) {
            $this->line('ownership_status writes:');
            foreach ($stats['statuses'] as $status => $count) {
                $this->line("  {$status}: {$count}");
            }
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderDryRunStats(string $table, array $counts): void
    {
        $this->info("[{$table}] dry-run (read-only) — {$counts['total']} rows inspected");
        $this->table(
            ['Auto-resolvable', 'Manual required', 'Ambiguous', 'Dangling', 'Unsupported', 'Already resolved'],
            [[
                $counts['auto_resolvable'],
                $counts['manual_required'],
                $counts['ambiguous'],
                $counts['dangling'],
                $counts['unsupported'],
                $counts['already_resolved'],
            ]],
        );
    }
}
