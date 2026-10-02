<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\GuardianRetention;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21.3C (E21.2G G1, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): Guardian personal data
 * (the Guardian, contacts, Documents, revoked account links, its own
 * consent events and preferences) is deleted GUARDIAN_RETENTION_YEARS
 * (adopted 1) calendar years after the Guardian last had a Student
 * relationship, only when nothing retained still needs it
 * (App\Support\Retention\GuardianRetention).
 *
 * - Each School in its own context, suspended and closed ones included. A
 *   held School is counted only. No default: unset deletes nothing.
 * - A revoked account link goes only past AUTHORITY_HISTORY_RETENTION_YEARS
 *   (unset: links keep the Guardian); an active one always keeps it.
 * - `--dry-run` counts with the same rule. Units are Guardians. Counts
 *   only in logs, never a name or contact.
 * - Run `platform:guardian-markers-backfill` first: a Guardian without a
 *   relationship and without a marker is `unresolved` and kept.
 */
class PruneGuardianRecords extends Command
{
    protected $signature = 'platform:guardian-retention-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Deletes Guardian personal data GUARDIAN_RETENTION_YEARS (adopted 1) after the Guardian last had a Student relationship (E21.2G G1; nothing while unset).';

    public function handle(GuardianRetention $guardians, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.guardian_years'));
            $authorityYears = RetentionPeriod::years(config('retention.authority_history_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.guardian_prune.unconfigured');
            $this->info('Guardian retention is not configured (GUARDIAN_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $cutoff = RetentionPeriod::yearsBeforeNow($years);
        $authorityCutoff = $authorityYears === null ? null : RetentionPeriod::yearsBeforeNow($authorityYears);
        $totals = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($guardians, $holds, $cutoff, $authorityCutoff, $batch, $dryRun, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                $counts = $guardians->prune($school, $cutoff, $authorityCutoff, $batch, $dryRun || $held);
                foreach ($counts as $outcome => $count) {
                    $totals[$outcome] += $count;
                }
                if ($held) {
                    // A held School counted only; none of its Guardians is reported as blocked or deletable.
                    $totals['held'] += $counts['eligible'];
                    $totals['dependency_blocked'] -= $counts['dependency_blocked'];
                }
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::GUARDIAN_RECORD, $totals);
        Log::info($dryRun ? 'retention.guardian_prune.dry_run' : 'retention.guardian_prune.completed', ['years' => $years, 'counts' => $totals]);

        $n = $dryRun ? $totals['eligible'] - $totals['held'] - $totals['dependency_blocked'] : $totals['deleted'];
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." the personal data of {$n} Guardian(s) (unresolved: {$totals['unresolved']}, dependency-blocked: {$totals['dependency_blocked']}, held: {$totals['held']}, errors: {$totals['errors']}).");

        return self::SUCCESS;
    }
}
