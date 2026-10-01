<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Application\Retention\AttendanceRetentionService;
use App\Domain\Guardians\Application\Retention\GuardianRelationshipRetentionService;
use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D7 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): Student and academic retention. Every period
 * runs from the Student's final exit (StudentRetentionEligibility); an
 * unresolved exit keeps everything.
 *
 * Two separate phases, each in the owning module:
 * - OPERATIONAL, STUDENT_OPERATIONAL_RETENTION_YEARS (adopted: 7):
 *   attendance records (Attendance), rollover items (Students) and Guardian
 *   relationships (Guardians);
 * - CORE, STUDENT_CORE_RETENTION_YEARS (adopted: 25): the Student with its
 *   placements, subject enrollments and Documents (Students). It runs only
 *   when no other retained row references the Student.
 *
 * Operational runs first, so a Student whose core period has passed has
 * already lost its operational rows when its core record is tested.
 * - Each School is processed in its own context, suspended ones included.
 *   A held School only counts.
 * - No default: an unset period deletes nothing.
 * - `--only=operational|core` limits the run; `--dry-run` counts only.
 * - Counts only in logs: Students, never identifiers.
 */
class PruneStudentRecords extends Command
{
    protected $signature = 'platform:student-retention-prune
        {--only= : operational or core}
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires Student operational history (7 y) and core academic records (25 y) after final exit (E21-D7; nothing while unset).';

    public function handle(
        AttendanceRetentionService $attendance,
        GuardianRelationshipRetentionService $relationships,
        StudentRecordRetentionService $records,
        RetentionHolds $holds,
        MetricsRecorder $metrics,
    ): int {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['operational', 'core'], true)) {
            $this->error('--only must be operational or core.');

            return self::FAILURE;
        }

        try {
            $operationalYears = $only === 'core' ? null : RetentionPeriod::years(config('retention.student_operational_years'));
            $coreYears = $only === 'operational' ? null : RetentionPeriod::years(config('retention.student_core_years'));
            $authorityYears = RetentionPeriod::years(config('retention.authority_history_years'));
            $configuredOperational = RetentionPeriod::years(config('retention.student_operational_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($coreYears !== null && $configuredOperational !== null && $coreYears < $configuredOperational) {
            $this->error('STUDENT_CORE_RETENTION_YEARS must not be shorter than STUDENT_OPERATIONAL_RETENTION_YEARS.');

            return self::FAILURE;
        }

        if ($operationalYears === null && $coreYears === null) {
            Log::info('retention.student_prune.unconfigured');
            $this->info('Student retention is not configured (STUDENT_OPERATIONAL_RETENTION_YEARS / STUDENT_CORE_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        // D6: a revoked Student account link may go with its Student only once past the authority period.
        $authorityCutoff = $authorityYears === null ? null : RetentionPeriod::yearsBeforeNow($authorityYears);
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $totals = array_fill_keys([RetentionMetrics::STUDENT_ATTENDANCE, RetentionMetrics::STUDENT_ROLLOVER_ITEM, RetentionMetrics::STUDENT_GUARDIAN_RELATIONSHIP, RetentionMetrics::STUDENT_CORE], $zero);

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($attendance, $relationships, $records, $holds, $operationalYears, $coreYears, $authorityCutoff, $dryRun, $batch, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                $today = CarbonImmutable::now(SchoolTimezone::resolve($school));
                $count = $dryRun || $held;

                if ($operationalYears !== null) {
                    $cutoff = $today->subYearsNoOverflow($operationalYears)->toDateString();
                    $this->add($totals[RetentionMetrics::STUDENT_ATTENDANCE], $attendance->prune($school, $cutoff, $batch, $count), $held);
                    $this->add($totals[RetentionMetrics::STUDENT_ROLLOVER_ITEM], $records->pruneRolloverItems($school, $cutoff, $batch, $count), $held);
                    $this->add($totals[RetentionMetrics::STUDENT_GUARDIAN_RELATIONSHIP], $relationships->prune($school, $cutoff, $batch, $count), $held);
                }

                if ($coreYears !== null) {
                    $cutoff = $today->subYearsNoOverflow($coreYears)->toDateString();
                    $this->add($totals[RetentionMetrics::STUDENT_CORE], $records->pruneCore($school, $cutoff, $authorityCutoff, $batch, $count, $operationalYears !== null), $held);
                }
            }
        });

        foreach ($totals as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.student_prune.dry_run' : 'retention.student_prune.completed', [
            'operational_years' => $operationalYears, 'core_years' => $coreYears, 'categories' => $totals,
        ]);

        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        if ($operationalYears !== null) {
            $a = $totals[RetentionMetrics::STUDENT_ATTENDANCE];
            $r = $totals[RetentionMetrics::STUDENT_ROLLOVER_ITEM];
            $g = $totals[RetentionMetrics::STUDENT_GUARDIAN_RELATIONSHIP];
            $this->info("{$verb} operational history: attendance of {$this->n($a, $dryRun)} Student(s), rollover items of {$this->n($r, $dryRun)}, Guardian relationships of {$this->n($g, $dryRun)} (unresolved exit: ".max($a['unresolved'], $r['unresolved'], $g['unresolved']).', dependency-blocked: '.($a['dependency_blocked'] + $r['dependency_blocked'] + $g['dependency_blocked']).', held: '.($a['held'] + $r['held'] + $g['held']).', errors: '.($a['errors'] + $r['errors'] + $g['errors']).').');
        }
        if ($coreYears !== null) {
            $c = $totals[RetentionMetrics::STUDENT_CORE];
            $this->info("{$verb} the core record of {$this->n($c, $dryRun)} Student(s) (unresolved exit: {$c['unresolved']}, dependency-blocked: {$c['dependency_blocked']}, held: {$c['held']}, errors: {$c['errors']}).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $total
     * @param  array<string, int>  $r
     */
    private function add(array &$total, array $r, bool $held): void
    {
        foreach ($r as $k => $n) {
            $total[$k] += $n;
        }

        if ($held) {
            // A held School counted only; none of its Students is reported as blocked or deletable.
            $total['held'] += $r['eligible'];
            $total['dependency_blocked'] -= $r['dependency_blocked'];
        }
    }

    /** @param  array<string, int>  $r */
    private function n(array $r, bool $dryRun): int
    {
        return $dryRun ? $r['eligible'] - $r['held'] - $r['dependency_blocked'] : $r['deleted'];
    }
}
