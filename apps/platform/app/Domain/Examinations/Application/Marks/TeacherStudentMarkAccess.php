<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * RES.4 (ADR 0068 §25; ADR 0063 §11, Tier 2) -- the owned-teacher
 * authorization path for StudentMark, every part required, none a substitute
 * for another:
 *
 *   authenticated session User + trusted School context + `mfa` (route)
 *   AND the interim development-only block (TeacherStudentMarkAvailability)
 *   AND `examinations.marks.teacher`           -- capability, never a role key
 *   AND a verified ActingEmployee TODAY        -- HR ActingEmployeeResolver
 *   AND per Student, on the paper's `scheduled_on`:
 *       P3 eligibility (Students)
 *       AND ownership: required -> TeachingAssignment for the Student's P3
 *           Section x the Offering; elective -> the Offering-wide elective
 *           assignment (TeachingOwnership::holdOffering())
 *       AND a current ADR 0038 processing basis
 *   AND the paper active, its marks open (writes), its year not closed.
 *
 * Never authority: a role name, School membership alone, a StudentSubject
 * enrollment alone, an Attendance capability, a timetable row or having
 * entered the mark before. The capability implies no administrative marks
 * key (view, manage, lock, corrections) and no Results authority.
 */
class TeacherStudentMarkAccess
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'examinations.marks.teacher';

    public function __construct(
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    /** For reads: the block, the capability, a fresh ActingEmployee today, the ownership periods. Nothing cached. */
    public function scope(User $actor, School $school): TeacherStudentMarkScope
    {
        TeacherStudentMarkAvailability::assertAvailable();
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $acting = $this->identities->resolve($actor, $school);

        return $this->scopeFor($school, $acting->employeeId);
    }

    public function scopeFor(School $school, string $employeeId): TeacherStudentMarkScope
    {
        return new TeacherStudentMarkScope($employeeId, $this->ownership->periods($school, $employeeId), $this->ownership->electivePeriods($school, $employeeId));
    }

    /** For writes: the guard StudentMarkService::record() runs inside its transaction. */
    public function guard(User $actor): TeacherStudentMarkGuard
    {
        return new TeacherStudentMarkGuard($actor, $this, $this->identities, $this->ownership);
    }
}
