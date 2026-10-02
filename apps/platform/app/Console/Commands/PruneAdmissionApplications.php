<?php

namespace App\Console\Commands;

use App\Domain\Admissions\Application\Retention\TerminalApplicationRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21.3C (E21.2G AD2, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): rejected and withdrawn
 * applications are deleted ADMISSIONS_TERMINAL_RETENTION_YEARS (adopted 1)
 * calendar years after their canonical `terminal_at`, with their applicant
 * once no application of it remains. Converted applications follow the
 * Student core record (E21.3B); live ones are working state.
 *
 * - Each School in its own context, suspended and closed ones included. A
 *   held School is counted only. No default: unset deletes nothing.
 * - `--dry-run` counts with the same rule. Units are applicants. Counts
 *   only in logs.
 * - Run `platform:admission-decisions-backfill` first: a terminal
 *   application without `terminal_at` is `unresolved` and kept.
 */
class PruneAdmissionApplications extends Command
{
    protected $signature = 'platform:admissions-retention-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Deletes rejected/withdrawn applications ADMISSIONS_TERMINAL_RETENTION_YEARS (adopted 1) after their terminal decision (E21.2G AD2; nothing while unset).';

    public function handle(TerminalApplicationRetentionService $applications, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.admissions_terminal_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.admissions_prune.unconfigured');
            $this->info('Admissions retention is not configured (ADMISSIONS_TERMINAL_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $cutoff = RetentionPeriod::yearsBeforeNow($years);
        $totals = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($applications, $holds, $cutoff, $batch, $dryRun, &$totals): void {
            foreach ($schools as $school) {
                foreach ($applications->prune($school, $cutoff, $batch, $dryRun, $holds->isHeld($school->id)) as $outcome => $count) {
                    $totals[$outcome] += $count;
                }
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::ADMISSION_APPLICATION, $totals);
        Log::info($dryRun ? 'retention.admissions_prune.dry_run' : 'retention.admissions_prune.completed', ['years' => $years, 'counts' => $totals]);

        $n = $dryRun ? $totals['eligible'] - $totals['held'] - $totals['dependency_blocked'] : $totals['deleted'];
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." the expired terminal applications of {$n} applicant(s) (unresolved: {$totals['unresolved']}, dependency-blocked: {$totals['dependency_blocked']}, held: {$totals['held']}, errors: {$totals['errors']}).");

        return self::SUCCESS;
    }
}
