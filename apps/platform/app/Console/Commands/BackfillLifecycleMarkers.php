<?php

namespace App\Console\Commands;

use App\Domain\Admissions\Application\Retention\AdmissionDecisionBackfill;
use App\Domain\Guardians\Application\Retention\GuardianMarkerBackfill;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E21.3C (E21.2G AD2/G1): one-time, rerunnable backfill of the two
 * lifecycle markers for rows that predate them, ONLY from trustworthy
 * audit evidence (AdmissionDecisionBackfill, GuardianMarkerBackfill). Operator console only, on the
 * maintenance connection (E21-RH.6: a past-dated marker is the schema owner's to set).
 * Anything without it stays NULL and `unresolved`, so it is kept.
 *
 * It deletes nothing and needs no hold check. `--dry-run` counts with the
 * same rule. Each School in its own context, bounded batches, counts only
 * (never an applicant or Guardian detail). Operator console; not scheduled.
 */
class BackfillLifecycleMarkers extends Command
{
    protected $signature = 'platform:lifecycle-markers-backfill
        {--only= : admissions or guardians}
        {--dry-run : Count what would be mapped without writing anything}';

    protected $description = 'Backfills Admissions terminal decision times and Guardian no-relationship markers from audit evidence (E21.3C; unresolved rows are kept).';

    public function handle(AdmissionDecisionBackfill $admissions, GuardianMarkerBackfill $guardians): int
    {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['admissions', 'guardians'], true)) {
            $this->error('--only must be admissions or guardians.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $zero = ['mapped' => 0, 'already_mapped' => 0, 'unresolved' => 0, 'error' => 0];
        $totals = ['admissions' => $zero, 'guardians' => $zero];

        // E21-RH.6: setting a missing marker to a past date is the schema owner's alone (the runtime role could
        // otherwise age a record into retention eligibility), so this operator command runs on the
        // maintenance connection, like the retention-hold commands.
        DB::usingConnection(RetentionHolds::MAINTENANCE_CONNECTION, function () use ($admissions, $guardians, $only, $dryRun, $batch, &$totals): void {
            School::query()->orderBy('id')->chunk(100, function ($schools) use ($admissions, $guardians, $only, $dryRun, $batch, &$totals): void {
                foreach ($schools as $school) {
                    foreach (['admissions' => $admissions, 'guardians' => $guardians] as $kind => $backfill) {
                        if ($only !== null && $only !== $kind) {
                            continue;
                        }
                        foreach ($backfill->run($school, $batch, $dryRun) as $outcome => $count) {
                            $totals[$kind][$outcome] += $count;
                        }
                    }
                }
            });
        });

        Log::info($dryRun ? 'retention.lifecycle_markers_backfill.dry_run' : 'retention.lifecycle_markers_backfill.completed', ['counts' => $totals]);
        foreach ($totals as $kind => $counts) {
            if ($only !== null && $only !== $kind) {
                continue;
            }
            $this->info(($dryRun ? 'Dry run: ' : '')."{$kind}: mapped {$counts['mapped']}, already mapped {$counts['already_mapped']}, unresolved {$counts['unresolved']}, errors {$counts['error']}.");
        }

        return $totals['admissions']['error'] + $totals['guardians']['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
