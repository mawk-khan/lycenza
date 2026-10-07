<?php

namespace Tests\Feature\Examinations\Concerns;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAccess;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkReadService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\User;

/**
 * RES.4 (ADR 0068 §25): the RES.2 marks world plus teachers -- a User with a
 * School membership and (by default) the `teacher` role, a linked active
 * Employee employed from 2026-01-01, an active MFA factor -- and their dated
 * ownership through the real TeachingAssignments services (required:
 * Section x the required Offering; elective: the elective Offering).
 * SYNTHETIC data only.
 */
trait CreatesTeacherStudentMarkFixtures
{
    use CreatesStudentMarkFixtures;

    /** @return array<string, mixed> */
    protected function teacherMarksWorld(): array
    {
        $w = $this->marksWorld();
        $w['assigner'] = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view', 'teaching.assignments.manage']);

        return $w;
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $employment
     * @return array{0: User, 1: Employee}
     */
    protected function markTeacher(array $w, ?string $roleKey = 'teacher', bool $linked = true, bool $mfa = true, array $employment = [], ?User $user = null): array
    {
        $user ??= $this->createUser();
        $membership = $this->createMembership($user, $w['school']);
        if ($roleKey !== null) {
            $this->assignSchoolRole($membership, $roleKey);
        }
        $employee = $this->createEmployee($w['school'], ['user_id' => $linked ? $user->id : null]);
        $this->createEmploymentRecord($employee, array_merge(['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active'], $employment));
        if ($mfa && ! $user->mfaFactors()->where('status', 'active')->exists()) {
            $this->enrollActiveMfaFactor($user);
        }

        return [$user, $employee];
    }

    /** @param  array<string, mixed>  $w */
    protected function ownSection(array $w, Employee $employee, string $section = 'a1', string $startsOn = '2026-06-01', ?string $endsOn = null): TeachingAssignment
    {
        return app(TeachingAssignmentService::class)->create($w['school'], $employee->id, $w[$section]->id, $w['required']->id, $startsOn, $endsOn, $w['assigner']);
    }

    /** @param  array<string, mixed>  $w */
    protected function ownElective(array $w, Employee $employee, ?SubjectOffering $offering = null, string $startsOn = '2026-06-01', ?string $endsOn = null): ElectiveTeachingAssignment
    {
        return app(ElectiveTeachingAssignmentService::class)->create($w['school'], $employee->id, ($offering ?? $w['elective'])->id, $startsOn, $endsOn, $w['assigner']);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<StudentMarkEntry>  $entries
     * @return list<array{studentId: string, studentMarkId: string, version: int}>
     */
    protected function teacherRecord(array $w, User $teacher, array $entries, ?ExaminationPaper $paper = null): array
    {
        return app(StudentMarkService::class)->record($w['school'], ($paper ?? $w['paper'])->id, $entries, $teacher, app(TeacherStudentMarkAccess::class)->guard($teacher));
    }

    /** @param  array<string, mixed>  $w @return array<string, mixed> */
    protected function teacherRead(array $w, User $teacher, ?ExaminationPaper $paper = null): array
    {
        return app(TeacherStudentMarkReadService::class)->paper($w['school'], ($paper ?? $w['paper'])->id, $teacher);
    }

    /** @param  array<string, mixed>  $read @return list<string> */
    protected function rowIds(array $read): array
    {
        return array_column($read['rows'], 'studentId');
    }
}
