<?php

namespace App\Console\Commands;

use App\Support\Tenancy\FinancialOwnershipResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * READ-ONLY reconciliation report for financial-record business ownership.
 *
 * Implements FIN-01 (Q7.1): it classifies each payable/receivable by whether the
 * persisted parent chain proves exactly one owning business. It has NO write
 * mode — there is deliberately no `--apply`, and no code path calls a mutating
 * query. Applying ownership is the explicit, dual-confirmation responsibility of
 * `business:financial-ownership-backfill` and `business:financial-ownership-assign`.
 *
 * Includes provenance metadata and complete per-record listing when `--list` is
 * combined with `--format=json` (defects D7/D8). The ALREADY_RESOLVED limitation
 * is reported only while the ownership schema is absent (defect D9).
 *
 * Runs regardless of `multi_business.enabled` because it only reads.
 */
class FinancialOwnershipReportCommand extends Command
{
    protected $signature = 'business:financial-ownership-report
        {--table=all : payables, receivables, or all}
        {--format=table : Output format: table or json}
        {--chunk=500 : Rows read per chunk (read-only)}
        {--max-ids=1000 : Max identifiers persisted per classification}
        {--list : Include a per-record classification line}';

    protected $description = 'READ-ONLY: classify financial-record business ownership (FIN-01 dry-run); never writes';

    public function handle(FinancialOwnershipResolver $resolver): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error("Unsupported --format '{$format}'. Use 'table' or 'json'.");

            return self::FAILURE;
        }

        $tables = $this->resolveTables($resolver);

        if ($tables === null) {
            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $maxIds = max(0, (int) $this->option('max-ids'));
        $list = (bool) $this->option('list');

        $report = [
            'generated_at' => now()->toIso8601String(),
            'provenance' => [
                'driver' => DB::connection()->getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
                'app_env' => app()->environment(),
                'app_version' => config('app.version', 'unknown'),
                'multi_business_enabled' => (bool) config('multi_business.enabled'),
                'generated_by' => 'business:financial-ownership-report',
                'arguments' => [
                    'table' => (string) $this->option('table'),
                    'chunk' => $chunk,
                    'max_ids' => $maxIds,
                    'list' => $list,
                ],
            ],
            'tables' => [],
            'limitations' => [],
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $report['tables'][$table] = ['present' => false, 'total' => 0];
                $report['limitations'][] = "Table '{$table}' is not present in this database; skipped.";

                continue;
            }

            $report['tables'][$table] = $this->classifyTable($resolver, $table, $chunk, $maxIds, $list);
            $report['tables'][$table]['present'] = true;
        }

        $report['limitations'][] = 'Counts reflect only the database this command was run against. '
            .'Unless run with explicit authorisation against production, they do not represent production data.';

        $hasFinancialOwnershipSchema = Schema::hasColumn('payables', 'business_id')
            && Schema::hasColumn('receivables', 'business_id')
            && Schema::hasColumn('payables', 'ownership_status');

        if (! $hasFinancialOwnershipSchema) {
            $report['limitations'][] = 'ALREADY_RESOLVED is currently unreachable: payables/receivables have no ownership schema in this phase.';
        } else {
            $report['limitations'][] = 'ALREADY_RESOLVED reflects persisted ownership; rows whose persisted business_id contradicts the parent chain surface as resolved.conflict (FIN-01 D3/D5).';
        }

        if ($format === 'json') {
            $this->output->writeln(
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        }

        $this->renderTable($report, $list);

        return self::SUCCESS;
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
            $this->error("Unsupported --table '{$requested}'. Supported: ".implode(', ', $resolver->supportedTables()).', all.');

            return null;
        }

        return [$requested];
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyTable(FinancialOwnershipResolver $resolver, string $table, int $chunk, int $maxIds, bool $list): array
    {
        $total = 0;
        $byClassification = [];
        $byReason = [];
        $records = [];

        DB::table($table)->orderBy('id')->chunkById($chunk, function ($rows) use (
            $resolver,
            $table,
            $maxIds,
            $list,
            &$total,
            &$byClassification,
            &$byReason,
            &$records
        ) {
            foreach ($rows as $row) {
                $result = $resolver->resolve($table, (int) $row->id);
                $total++;

                $class = $result->classification;

                if (! isset($byClassification[$class])) {
                    $byClassification[$class] = ['count' => 0, 'ids' => [], 'ids_truncated' => 0];
                }

                $byClassification[$class]['count']++;

                if (count($byClassification[$class]['ids']) < $maxIds) {
                    $byClassification[$class]['ids'][] = $result->id;
                } else {
                    $byClassification[$class]['ids_truncated']++;
                }

                $byReason[$result->reasonCode] = ($byReason[$result->reasonCode] ?? 0) + 1;

                if ($list && count($records) < $maxIds) {
                    $records[] = [
                        'id' => $result->id,
                        'classification' => $result->classification,
                        'reason_code' => $result->reasonCode,
                        'proposed_business_id' => $result->proposedBusinessId,
                    ];
                }
            }
        });

        ksort($byClassification);
        ksort($byReason);

        $data = [
            'total' => $total,
            'by_classification' => $byClassification,
            'by_reason' => $byReason,
        ];

        if ($list) {
            $data['records'] = $records;
            $data['records_truncated'] = $total > count($records);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTable(array $report, bool $list): void
    {
        $this->info('Financial ownership report (READ-ONLY dry-run)');
        $this->line('multi_business.enabled = '.($report['provenance']['multi_business_enabled'] ? 'true' : 'false'));

        foreach ($report['tables'] as $table => $data) {
            if (($data['present'] ?? false) !== true) {
                $this->warn("[{$table}] table not present; skipped.");

                continue;
            }

            $this->newLine();
            $this->line("[{$table}] total inspected: {$data['total']}");

            if ($data['total'] === 0) {
                continue;
            }

            $rows = [];
            foreach ($data['by_classification'] as $class => $meta) {
                $rows[] = [$class, $meta['count'], $meta['ids_truncated'] > 0 ? 'yes' : 'no'];
            }
            $this->table(['Classification', 'Count', 'IDs truncated'], $rows);

            $this->line('Reason codes:');
            foreach ($data['by_reason'] as $reason => $count) {
                $this->line("  {$reason}: {$count}");
            }

            if ($list && ! empty($data['records'])) {
                $this->line('Records:');
                foreach ($data['records'] as $record) {
                    $proposed = $record['proposed_business_id'] ?? '-';
                    $this->line(sprintf(
                        '  [%s] #%d %s (%s) -> business %s',
                        $table,
                        $record['id'],
                        $record['classification'],
                        $record['reason_code'],
                        $proposed,
                    ));
                }

                if (($data['records_truncated'] ?? false)) {
                    $this->line('  ... truncated; raise --max-ids to list more records.');
                }
            } elseif (! $list) {
                $this->line('(run with --list to print per-record identifiers)');
            }
        }

        if ($report['limitations'] !== []) {
            $this->newLine();
            $this->line('Limitations:');
            foreach ($report['limitations'] as $limitation) {
                $this->line('  - '.$limitation);
            }
        }
    }
}
