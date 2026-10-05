<?php

namespace App\Domain\Attendance\Application\Retention;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D7 operational Student history (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): a Student's attendance
 * records are kept 7 calendar years after the Student's final exit
 * (StudentRetentionEligibility), then deleted. Only
 * `platform:student-retention-prune` calls this, never a request.
 *
 * - Scope: only `attendance_records`, the one Student's rows.
 * - Sessions are kept. An `attendance_sessions` row is the Section's
 *   register header (Section, offering, teacher, period), not a Student
 *   record. D7 does not govern it: A1 does, once it is empty
 *   (AttendanceSessionRetentionService, E21.3D).
 * - Corrections overwrite `status`; their prior values live only in the
 *   audit ledger, which expires on its own D1 clock. Nothing is copied
 *   into new audit events.
 * - Locking, the exit recheck and per-Student transactions come from
 *   StudentRetentionEligibility::purgeExitedBefore(). A row another table
 *   still references is never cascaded away (`dependency_blocked`).
 * - Units are Students.
 */
final class AttendanceRetentionService
{
    public function __construct(
        private readonly StudentRetentionEligibility $students,
        private readonly ReferencingRows $references,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('attendance_record', $dryRun, $school->id, ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoffDate, $batch, $dryRun, $only));
    }

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // The one sanctioned delete path for attendance records
        // (AttendanceArchitectureGuardTest); SchoolScope applies.
        $records = fn (string $studentId): EloquentBuilder => AttendanceRecord::query()
            ->whereIn('student_enrollment_id', DB::table('student_enrollments')->select('id')->where('student_id', $studentId));

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $students) => $students->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('attendance_records as r')
                ->join('student_enrollments as e', 'e.id', '=', 'r.student_enrollment_id')->whereColumn('e.student_id', 'students.id')),
            fn (string $studentId): array => array_filter([$this->references->first('attendance_records', $school->id, $records($studentId)->pluck('id')->all())]),
            fn (string $studentId): ?array => $records($studentId)->delete() > 0 ? [] : null,
            $only,
        );
    }
}
