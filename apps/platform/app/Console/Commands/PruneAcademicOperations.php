<?php

namespace App\Console\Commands;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Domain\Attendance\Application\Retention\AttendanceSessionRetentionService;
use App\Domain\CurriculumDelivery\Application\Retention\CurriculumDeliveryRetentionService;
use App\Domain\Timetable\Application\Retention\TimetableEntryRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\LmsResourceRetention;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21.3D (E21.2G A1, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): year-bound academic
 * operations are deleted ACADEMIC_OPERATIONS_RETENTION_YEARS (adopted 7)
 * calendar years after the end of their authoritative Academic Year. A
 * closed list, each in its owning module, run in dependency order:
 * - curriculum deliveries (CurriculumDelivery);
 * - attendance register headers, only once empty (Attendance);
 * - timetable entries, only once no header references them (Timetable);
 * - LMS Learning Content and Assignments, with their audiences and
 *   Documents, past the E21-D6 owner/audience minimum (LMS; needs
 *   AUTHORITY_HISTORY_RETENTION_YEARS for teacher-owned ones).
 * Syllabus units and examination schedules are tenant-lifetime School
 * configuration (E21.2G A2): nothing here touches them.
 *
 * - Each School in its own context, suspended and closed ones included. A
 *   held School is counted only. No default: unset deletes nothing.
 * - `--dry-run` counts with the same rules. Counts only in logs.
 * - Correctness never depends on the order: each category rechecks its own
 *   dependencies under its own locks; a later run picks up what an earlier
 *   category released.
 */
class PruneAcademicOperations extends Command
{
    protected $signature = 'platform:academic-retention-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Deletes year-bound academic operations ACADEMIC_OPERATIONS_RETENTION_YEARS (adopted 7) after their Academic Year ended (E21.2G A1; nothing while unset).';

    public function handle(
        CurriculumDeliveryRetentionService $deliveries,
        AttendanceSessionRetentionService $sessions,
        TimetableEntryRetentionService $timetable,
        LmsResourceRetention $lms,
        RetentionHolds $holds,
        MetricsRecorder $metrics,
    ): int {
        try {
            $years = RetentionPeriod::years(config('retention.academic_operations_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.academic_prune.unconfigured');
            $this->info('Academic retention is not configured (ACADEMIC_OPERATIONS_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $categories = [RetentionMetrics::CURRICULUM_DELIVERY, RetentionMetrics::ATTENDANCE_SESSION, RetentionMetrics::TIMETABLE_ENTRY, RetentionMetrics::LEARNING_CONTENT, RetentionMetrics::ASSIGNMENT];
        $totals = array_fill_keys($categories, $zero);

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($deliveries, $sessions, $timetable, $lms, $holds, $years, $batch, $dryRun, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                $cutoff = AcademicYearRetention::cutoff($school, $years);
                $runs = [
                    RetentionMetrics::CURRICULUM_DELIVERY => fn () => $deliveries->prune($school, $cutoff, $batch, $dryRun, $held),
                    RetentionMetrics::ATTENDANCE_SESSION => fn () => $sessions->prune($school, $cutoff, $batch, $dryRun, $held),
                    RetentionMetrics::TIMETABLE_ENTRY => fn () => $timetable->prune($school, $cutoff, $batch, $dryRun, $held),
                    RetentionMetrics::LEARNING_CONTENT => fn () => $lms->prune('learning_content', $school, $cutoff, $batch, $dryRun, $held),
                    RetentionMetrics::ASSIGNMENT => fn () => $lms->prune('assignment', $school, $cutoff, $batch, $dryRun, $held),
                ];
                foreach ($runs as $category => $run) {
                    foreach ($run() as $outcome => $count) {
                        $totals[$category][$outcome] += $count;
                    }
                }
            }
        });

        foreach ($totals as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.academic_prune.dry_run' : 'retention.academic_prune.completed', ['years' => $years, 'categories' => $totals]);

        $labels = [
            RetentionMetrics::CURRICULUM_DELIVERY => 'curriculum deliveries',
            RetentionMetrics::ATTENDANCE_SESSION => 'attendance register headers',
            RetentionMetrics::TIMETABLE_ENTRY => 'timetable entries',
            RetentionMetrics::LEARNING_CONTENT => 'LMS learning content',
            RetentionMetrics::ASSIGNMENT => 'LMS assignments',
        ];
        foreach ($totals as $category => $c) {
            $n = $dryRun ? $c['eligible'] - $c['held'] - $c['dependency_blocked'] : $c['deleted'];
            $this->info(($dryRun ? 'Dry run: would delete ' : 'Deleted ')."{$n} {$labels[$category]} (dependency-blocked: {$c['dependency_blocked']}, held: {$c['held']}, errors: {$c['errors']}).");
        }

        return self::SUCCESS;
    }
}
