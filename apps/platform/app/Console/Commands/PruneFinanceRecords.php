<?php

namespace App\Console\Commands;

use App\Domain\Finance\Application\Retention\FinanceRetentionEligibility;
use App\Domain\Finance\Application\Retention\FinanceRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D8 (docs/security/E21-RETENTION-DETERMINATION.md, ADR 0064, project-
 * adopted, pending legal ratification): expires the detail of financial
 * periods closed at least FINANCE_RETENTION_YEARS (>= 8) calendar years ago,
 * in settled, dependency-safe units, through `FinanceRetentionService`.
 *
 * Fail-closed:
 * - nothing is deleted unless FINANCE_RETENTION_ENABLED=true AND
 *   FINANCE_RETENTION_YEARS is set (>= 8; anything shorter or not a
 *   whole number is an error);
 * - a dry run needs only the period and deletes nothing;
 * - every School is walked, suspended and closed ones included, each in
 *   its own context; a held School is only counted;
 * - the accounting check runs before and after; a failure deletes nothing
 *   further and exits non-zero;
 * - output, logs and metrics are counts only.
 */
class PruneFinanceRecords extends Command
{
    protected $signature = 'platform:finance-retention-prune
        {--school= : Only this School (id)}
        {--dry-run : Count what would be expired without deleting anything}';

    protected $description = 'Expires settled Finance detail of periods closed >= FINANCE_RETENTION_YEARS (8) ago (E21-D8; off unless explicitly enabled).';

    public function handle(FinanceRetentionService $retention, FinanceRetentionEligibility $eligibility, MetricsRecorder $metrics): int
    {
        try {
            $years = $eligibility->years();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($years === null || (! $dryRun && ! $eligibility->enabled())) {
            Log::info('retention.finance_prune.disabled', ['configured' => $years !== null, 'enabled' => $eligibility->enabled()]);
            $this->info('Finance retention is not enabled (FINANCE_RETENTION_ENABLED / FINANCE_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) config('retention.batch_size'));
        $totals = ['periods_eligible' => 0, 'eligible' => 0, 'deleted' => 0, 'held' => 0, 'dependency_blocked' => 0, 'errors' => 0, 'verification_failed' => 0];

        School::query()
            ->when($this->option('school'), fn ($q, $id) => $q->whereKey((string) $id))
            ->orderBy('id')
            ->chunk(100, function ($schools) use ($retention, $limit, $dryRun, $years, &$totals): void {
                foreach ($schools as $school) {
                    foreach ($retention->prune($school, $limit, $dryRun, $years) as $key => $count) {
                        $totals[$key] += $count;
                    }
                }
            });

        RetentionMetrics::record($metrics, RetentionMetrics::FINANCE_UNIT, [
            'eligible' => $totals['eligible'], 'deleted' => $totals['deleted'], 'held' => $totals['held'],
            'dependency_blocked' => $totals['dependency_blocked'], 'errors' => $totals['errors'] + $totals['verification_failed'],
        ]);
        Log::info($dryRun ? 'retention.finance_prune.dry_run' : 'retention.finance_prune.completed', ['years' => $years, 'counts' => $totals]);

        $this->info(sprintf('%s periods_eligible=%d units_eligible=%d %s=%d held=%d blocked=%d errors=%d verification_failed=%d',
            $dryRun ? '[dry-run]' : '[applied]', $totals['periods_eligible'], $totals['eligible'], $dryRun ? 'would_delete' : 'deleted',
            $dryRun ? $totals['eligible'] - $totals['held'] : $totals['deleted'], $totals['held'], $totals['dependency_blocked'], $totals['errors'], $totals['verification_failed']));

        return $totals['verification_failed'] > 0 || $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
