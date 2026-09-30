<?php

namespace Tests\Concerns;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * TCH.2 fixtures: one School with an active 2026-27 AcademicYear
 * (2026-04-01 .. 2027-03-31), a Section and a required SubjectOffering in
 * the same Campus/GradeLevel context, an active Employee with a current
 * employment, and an administrator holding teaching.assignments.*.
 *
 * Requires CreatesTenancyFixtures on the using test class.
 */
trait CreatesTeachingAssignmentFixtures
{
    /**
     * @return array{school: School, year: AcademicYear, campus: Campus, grade: GradeLevel, section: Section, offering: SubjectOffering, employee: Employee, admin: User}
     */
    protected function teachingWorld(?School $school = null, string $yearStatus = 'active'): array
    {
        $school ??= $this->createSchool();
        // An explicit unique code: a second world in the SAME School (a draft
        // year) must never collide with the first on
        // academic_years_school_id_code_unique through the factory's random pick.
        $code = 'TA'.strtoupper(Str::random(8));
        $year = $this->createAcademicYear($school, ['code' => $code, 'name' => "Teaching {$code}", 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => $yearStatus]);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);

        return [
            'school' => $school,
            'year' => $year,
            'campus' => $campus,
            'grade' => $grade,
            'section' => $section,
            'offering' => $offering,
            'employee' => $this->employedTeacher($school),
            'admin' => $this->createUserWithCapabilities($school, ['teaching.assignments.view', 'teaching.assignments.manage']),
        ];
    }

    /** An active Employee (no linked User) with an open, active employment from 2026-01-01. */
    protected function employedTeacher(School $school, array $employment = []): Employee
    {
        $employee = $this->createEmployee($school, ['user_id' => null]);
        $this->createEmploymentRecord($employee, array_merge(['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active'], $employment));

        return $employee;
    }

    /** @param  array<string, mixed>  $world */
    protected function assign(array $world, string $startsOn = '2026-06-01', ?string $endsOn = null, ?Employee $employee = null, ?User $actor = null): TeachingAssignment
    {
        return app(TeachingAssignmentService::class)->create(
            $world['school'],
            ($employee ?? $world['employee'])->id,
            $world['section']->id,
            $world['offering']->id,
            $startsOn,
            $endsOn,
            $actor ?? $world['admin'],
        );
    }
}
