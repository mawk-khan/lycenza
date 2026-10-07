<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkStudentNotFoundException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\Students\Application\SubjectOfferingEligibility;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use LogicException;

/**
 * RES.4 (ADR 0068 §25.6-§25.7): the teacher write check, run by
 * StudentMarkService::record() INSIDE its transaction:
 *
 *   holdActor()    -- the development-only block, `examinations.marks.teacher`,
 *                     then ActingEmployeeResolver::hold() TODAY: School ->
 *                     membership -> User -> Employee -> EmploymentRecord FOR
 *                     SHARE (ADR 0063 order), before any marks lock;
 *   admitPaper()   -- the teacher must own the paper's Offering on its date
 *                     (any Section, or the elective), else the same 404 as a
 *                     missing paper -- before the paper's state is disclosed;
 *   admitStudent() -- after P3 locked the Student's placement (and elective
 *                     row): an ineligible Student, or one the teacher does not
 *                     own on `scheduled_on`, is the same non-disclosing 404;
 *                     ownership is TeachingOwnership::holdOffering() -- the
 *                     covering assignment FOR SHARE, so ending it either
 *                     commits first (and this refuses) or waits for the mark.
 *
 * ADR 0038, the version guard, the write and its history stay
 * StudentMarkService's. Audit events are the teacher ones, with the acting
 * Employee and the ownership source (ids only).
 */
final class TeacherStudentMarkGuard implements StudentMarkWriteGuard
{
    use AuthorizesCapability;

    private ?ActingEmployee $acting = null;

    public function __construct(
        private readonly User $actor,
        private readonly TeacherStudentMarkAccess $access,
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    public function holdActor(School $school): void
    {
        TeacherStudentMarkAvailability::assertAvailable();
        $this->authorizeCapabilityFor($this->actor, TeacherStudentMarkAccess::CAPABILITY, $school);

        $this->acting = $this->identities->hold($this->actor, $school);
    }

    public function admitPaper(School $school, ?ExaminationPaper $paper): void
    {
        if ($paper === null || ! $this->access->scopeFor($school, $this->acting()->employeeId)->ownsOffering($paper->subject_offering_id, $paper->scheduled_on->toDateString())) {
            throw new TeacherStudentMarkPaperNotFoundException;
        }
    }

    public function admitStudent(School $school, ExaminationPaper $paper, string $studentId, SubjectOfferingEligibility $eligibility): array
    {
        if (! $eligibility->eligible) {
            throw new TeacherStudentMarkStudentNotFoundException($studentId);
        }

        $required = $eligibility->source === SubjectOfferingEligibility::SOURCE_REQUIRED;
        if (! $this->ownership->holdOffering($school, $this->acting()->employeeId, $paper->subject_offering_id, $required ? $eligibility->sectionId : null, $paper->scheduled_on->toDateString())) {
            throw new TeacherStudentMarkStudentNotFoundException($studentId);
        }

        return ['employeeId' => $this->acting()->employeeId, 'ownershipSource' => TeacherStudentMarkScope::sourceOf($eligibility)];
    }

    public function auditEvent(bool $created): string
    {
        return $created ? 'examinations.student_mark.teacher_recorded' : 'examinations.student_mark.teacher_changed';
    }

    private function acting(): ActingEmployee
    {
        return $this->acting ?? throw new LogicException('TeacherStudentMarkGuard::holdActor() must run first.');
    }
}
