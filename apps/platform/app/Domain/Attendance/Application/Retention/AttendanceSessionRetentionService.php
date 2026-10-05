<?php

namespace App\Domain\Attendance\Application\Retention;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Models\School;
use App\Support\Retention\RetentionBatch;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification): an
 * attendance register header (`attendance_sessions`) is School teaching
 * evidence, kept 7 calendar years after the end of its authoritative
 * Academic Year (`attendance_sessions.academic_year_id`, pinned by its
 * composite foreign keys to its Section and SubjectOffering; the clock is
 * AcademicYearRetention's), then deleted. Only
 * `platform:academic-retention-prune` calls this, never a request.
 *
 * - Only once it is EMPTY: a header with any attendance record left (a
 *   Student's record goes 7 years after that Student's final exit, E21.2D)
 *   or any other referencing row is `dependency_blocked` and kept. The
 *   check is the live rows, re-applied under the row lock, never a count.
 * - Its teacher provenance (`teacher_id`) goes with it, never earlier, so
 *   the Employee stops being referenced only by the header's own expiry.
 *   Correction history is in the audit ledger (D1) and stays there.
 * - Not tied to any Student's exit or Employee's separation, and never to
 *   `created_at`, a status or the current year: a current or future year
 *   is never past its period.
 * - Bounded batches in id order (RetentionBatch); a held School is counted
 *   only. Counts only, never content.
 */
final class AttendanceSessionRetentionService
{
    private const TABLE = 'attendance_sessions';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionBatch $batches,
    ) {}

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, bool $held): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('attendance_session', $dryRun || $held, $school->id, ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoffDate, $batch, $dryRun, $held));
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
