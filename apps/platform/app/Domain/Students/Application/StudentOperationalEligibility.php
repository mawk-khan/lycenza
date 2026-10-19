<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * ORG.7D-4 (ADR 0076 §40): THE write-time proof that a Student is a current
 * operational Student of one Campus, held stable until the caller's
 * transaction commits. A domain that creates NEW operational work for a
 * Student on a Campus (a Hostel residency, a Canteen order, ...) calls this
 * inside its write transaction; StudentPurposeLookup and
 * StudentOperationalCampusResolver stay advisory and never prove it.
 *
 * It share-locks exactly the rows that decide the answer, in one fixed
 * order, and answers from the locked rows:
 *
 *   1. the School's active AcademicYear  (CurrentAcademicYearResolver::shareLockActiveId)
 *   2. the Student                       (status `active`)
 *   3. the Student's current Enrollment  (constrainToCurrentPlacement(): `active` in that year)
 *   4. the Enrollment's Campus           (ActiveCampus: `active`, same School)
 *
 * FOR SHARE blocks every writer that changes the answer. Year activation
 * and close update row 1. A Student status change updates row 2. A transfer,
 * completion, withdrawal or cancellation locks row 3 FOR UPDATE. A Campus
 * deactivation updates row 4. Each writer therefore waits until the
 * consuming write commits. If the writer committed first, PostgreSQL
 * re-checks the locked row's new version, so the answer is false: a refusal,
 * never a stale yes. A new row created by a concurrent writer is never
 * guessed at. The locks stay shared, so concurrent consumers do not
 * serialize against one another, and nothing School-wide is locked.
 *
 * - Data validity, never authorization: the caller authorizes its own
 *   capability and Campus scope first and passes the target Campus.
 * - Returns a boolean only, never a model for downstream use.
 * - Tenant-safe: runs inside the target School's TenantContext with explicit
 *   `school_id` predicates, so it never locks another School's rows.
 */
final class StudentOperationalEligibility
{
    public function __construct(
        private readonly CurrentAcademicYearResolver $years,
        private readonly StudentEnrollmentReadService $enrollments,
        private readonly ActiveCampus $campuses,
        private readonly TenantContext $context,
    ) {}

    /**
     * True only while $studentId is an active Student of $school whose current
     * placement (active Enrollment in the active year) is on the active
     * Campus $campusId -- and then those rows stay share-locked until the
     * caller's transaction ends.
     */
    public function lockOperationalOn(School $school, string $studentId, string $campusId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StudentOperationalEligibility::lockOperationalOn() must run inside the consuming write transaction.');
        }
        if (! Str::isUuid($studentId) || ! Str::isUuid($campusId)) {
            return false;
        }

        return $this->context->withSchool($school, function () use ($school, $studentId, $campusId): bool {
            $yearId = $this->years->shareLockActiveId($school);
            if ($yearId === null) {
                return false;
            }

            $status = Student::query()->where('school_id', $school->id)->whereKey($studentId)->sharedLock()->value('status');
            if ($status !== 'active') {
                return false;
            }

            $placementCampusId = $this->enrollments->constrainToCurrentPlacement(StudentEnrollment::query(), $yearId)
                ->where('school_id', $school->id)
                ->where('student_id', $studentId)
                ->sharedLock()
                ->value('campus_id');
            if ($placementCampusId !== $campusId) {
                return false;
            }

            return $this->campuses->find($school, $campusId) !== null;
        });
    }
}
