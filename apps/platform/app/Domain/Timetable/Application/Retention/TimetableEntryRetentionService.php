<?php

namespace App\Domain\Timetable\Application\Retention;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Models\School;
use App\Support\Retention\RetentionBatch;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification): a
 * timetable entry (a Section's weekly slot for one Offering and teacher) is
 * School teaching evidence, kept 7 calendar years after the end of its
 * authoritative Academic Year (`timetable_entries.academic_year_id`, pinned
 * by its composite foreign keys to its Section and SubjectOffering; the
 * clock is AcademicYearRetention's), then deleted. Only
 * `platform:academic-retention-prune` calls this, never a request.
 *
 * - Only once no register header references it (headers go first, once
 *   empty); any other referencing row keeps it too.
 * - Its teacher (`teacher_id`) stays referenced until the entry itself
 *   goes. Periods, rooms and other reusable School configuration are not
 *   year-bound and stay (tenant lifetime).
 * - Not tied to any Student's exit or Employee's separation, and never to
 *   `created_at`, a status or the current year: a current or future year
 *   is never past its period.
 * - Bounded batches in id order (RetentionBatch); a held School is counted
 *   only. Counts only, never content.
 */
final class TimetableEntryRetentionService
{
    private const TABLE = 'timetable_entries';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionBatch $batches,
    ) {}

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('timetable_entry', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoffDate, $batch, $dryRun, $held));
    }

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, string $cutoffDate, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, fn (): array => $this->batches->prune(
            self::TABLE,
            fn () => AcademicYearRetention::endedBefore(DB::table(self::TABLE.' as t')->where('t.school_id', $school->id), 't', $cutoffDate),
            $batch,
            $dryRun,
            $held,
        ));
    }
}
