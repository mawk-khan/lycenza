<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\StudentTransferUnavailableException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decisions F3-D2 to F3-D6): the write-time
 * checks of a Campus-scoped transfer, run by StudentEnrollmentService::
 * transferPlacement() AFTER it holds the Student (FOR SHARE), both Sections
 * and the source Enrollment (FOR UPDATE), and inside the transaction that
 * already holds the School's active year (FOR SHARE) -- never on a stale page
 * or picker answer.
 *
 * - The Student is active; the source is the CURRENT placement (the active
 *   year; still `active` -- transferPlacement re-checked it under its lock).
 * - The scope covers BOTH the source and the destination Campus, and they
 *   differ: a Campus transfer moves a Student between Campuses (a Section
 *   change within one Campus stays whole-School).
 * - No StudentTransferBlocker reports an active source-Campus relationship
 *   (Hostel residency, Transport rider assignment, elective).
 * Destination Section/Campus activity is the F-2 rule, checked next under
 * its own locks. Every refusal is the same generic answer.
 */
final class StudentTransferCampusGuard
{
    /** @param  iterable<StudentTransferBlocker>  $blockers */
    public function __construct(
        private readonly School $school,
        private readonly CampusScope $scope,
        private readonly string $activeYearId,
        private readonly iterable $blockers,
    ) {}

    public function beforeTransfer(Student $lockedStudent, StudentEnrollment $lockedSource, Section $lockedTarget): void
    {
        if ($lockedStudent->status !== 'active'
            || $lockedSource->academic_year_id !== $this->activeYearId
            || $lockedTarget->academic_year_id !== $this->activeYearId
            || $lockedSource->campus_id === $lockedTarget->campus_id
            || ! $this->scope->covers($lockedSource->campus_id)
            || ! $this->scope->covers($lockedTarget->campus_id)) {
            throw new StudentTransferUnavailableException;
        }

        foreach ($this->blockers as $blocker) {
            if ($blocker->blocksCampusTransfer($this->school, $lockedStudent->id, $lockedSource->id, $lockedSource->campus_id)) {
                throw new StudentTransferUnavailableException;
            }
        }
    }
}
