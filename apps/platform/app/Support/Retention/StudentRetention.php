<?php

namespace App\Support\Retention;

use App\Domain\Admissions\Application\Retention\ConvertedApplicationRetentionService;
use App\Domain\Attendance\Application\Retention\AttendanceRetentionService;
use App\Domain\Communications\Application\Retention\StudentConsentRetentionService;
use App\Domain\Guardians\Application\Retention\GuardianRelationshipRetentionService;
use App\Domain\Hostel\Application\Retention\HostelResidencyRetentionService;
use App\Domain\Library\Application\Retention\LibraryLoanRetentionService;
use App\Domain\Students\Application\Retention\StudentCoreParticipant;
use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Domain\Transport\Application\Retention\TransportAssignmentRetentionService;
use App\Models\School;
use Carbon\CarbonInterface;
use Closure;

/**
 * E21.2D/E21.3B (E21-D7, docs/security/E21-RETENTION-DETERMINATION.md):
 * the ONE composition of every Student-linked retention purge, so the
 * scheduled run (`platform:student-retention-prune`) and a reviewed
 * erasure case use the very same closed lists. It composes the owning
 * modules' purges and adds no rule of its own: every eligibility rule
 * (StudentRetentionEligibility, the final exit) and every delete stays in
 * the owning module. It sits above Students so that no module dependency
 * is inverted (Library, Transport, Hostel, Attendance, Guardians,
 * Admissions and Communications all depend on Students, never the
 * reverse).
 *
 * - OPERATIONAL (7 y after final exit): attendance records, rollover
 *   items, unreferenced Guardian relationships, and (E21.3B) returned
 *   Library loans and ended Transport/Hostel assignments.
 * - CORE (25 y after final exit): the Student record with, in the same
 *   unit, its processing authorizations and the core participants below.
 *
 * Adding a table here is a classification decision
 * (StudentRetentionClassificationTest); there is no generic or
 * table-driven path.
 */
final class StudentRetention
{
    public function __construct(
        private readonly AttendanceRetentionService $attendance,
        private readonly StudentRecordRetentionService $records,
        private readonly GuardianRelationshipRetentionService $relationships,
        private readonly LibraryLoanRetentionService $library,
        private readonly TransportAssignmentRetentionService $transport,
        private readonly HostelResidencyRetentionService $hostel,
        private readonly ConvertedApplicationRetentionService $admissions,
        private readonly StudentConsentRetentionService $consent,
    ) {}

    /** @return list<string> the operational categories, in run order */
    public static function operationalCategories(): array
    {
        return [
            RetentionMetrics::STUDENT_ATTENDANCE, RetentionMetrics::STUDENT_ROLLOVER_ITEM, RetentionMetrics::STUDENT_GUARDIAN_RELATIONSHIP,
            RetentionMetrics::STUDENT_LIBRARY_LOAN, RetentionMetrics::STUDENT_TRANSPORT_ASSIGNMENT, RetentionMetrics::STUDENT_HOSTEL_RESIDENCY,
        ];
    }

    /**
     * Runs every operational purge for one School.
     *
     * @return array<string, array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}> category => counts
     */
    public function operational(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        return [
            RetentionMetrics::STUDENT_ATTENDANCE => $this->attendance->prune($school, $cutoffDate, $batch, $dryRun, $only),
            RetentionMetrics::STUDENT_ROLLOVER_ITEM => $this->records->pruneRolloverItems($school, $cutoffDate, $batch, $dryRun, $only),
            RetentionMetrics::STUDENT_GUARDIAN_RELATIONSHIP => $this->relationships->prune($school, $cutoffDate, $batch, $dryRun, $only),
            RetentionMetrics::STUDENT_LIBRARY_LOAN => $this->library->prune($school, $cutoffDate, $batch, $dryRun, $only),
            RetentionMetrics::STUDENT_TRANSPORT_ASSIGNMENT => $this->transport->prune($school, $cutoffDate, $batch, $dryRun, $only),
            RetentionMetrics::STUDENT_HOSTEL_RESIDENCY => $this->hostel->prune($school, $cutoffDate, $batch, $dryRun, $only),
        ];
    }

    /**
     * Runs the core purge for one School. `$afterOperational` (counting
     * only): the operational phase of the same run clears its tables first.
     *
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function core(School $school, string $cutoffDate, ?CarbonInterface $authorityCutoff, int $batch, bool $dryRun, bool $afterOperational = false, ?string $only = null): array
    {
        return $this->records->pruneCore($school, $cutoffDate, $authorityCutoff, $batch, $dryRun, $this->participants(), $afterOperational ? $this->cleared() : [], $only);
    }

    /** E21.2F (erasure planning, read-only): what keeps one Student's core record, or null. */
    public function coreBlockerFor(School $school, string $studentId, ?CarbonInterface $authorityCutoff, bool $afterOperational): ?string
    {
        return $this->records->coreBlockerFor($school, $studentId, $authorityCutoff, $this->participants(), $afterOperational ? $this->cleared() : []);
    }

    /** @return list<StudentCoreParticipant> rows other modules keep with the core record */
    public function participants(): array
    {
        return [$this->admissions, $this->consent, $this->relationships];
    }

    /**
     * The tables the operational phase clears => whether it leaves open rows
     * of a Student behind (an unreturned loan, an active assignment), or null
     * when it clears every row of an eligible Student.
     *
     * @return array<string, (Closure(string): bool)|null>
     */
    public function cleared(): array
    {
        return [
            'attendance_records' => null,
            'enrollment_rollover_items' => null,
            'student_guardian_relationships' => null,
            'library_loans' => $this->library->hasOpen(...),
            'transport_student_assignments' => $this->transport->hasOpen(...),
            'hostel_residency_assignments' => $this->hostel->hasOpen(...),
        ];
    }
}
