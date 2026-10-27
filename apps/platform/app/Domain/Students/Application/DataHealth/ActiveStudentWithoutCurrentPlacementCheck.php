<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * An `active` Student with no `active` Enrollment in the School's active
 * AcademicYear (the same "current placement" rule as
 * StudentEnrollmentReadService::currentFor()). A placement is not a
 * contractual requirement for an active Student, so this is Review --
 * but registers, rosters and fee runs all start from placements, so such
 * a Student is invisible to them. No active year: nothing to compare
 * (academic_setup.no_active_year covers that).
 */
class ActiveStudentWithoutCurrentPlacementCheck implements DataHealthCheck
{
    use PresentsStudentFindings;

    public function __construct(
        private readonly CurrentAcademicYearResolver $years,
        private readonly StudentEnrollmentReadService $placements,
    ) {}

    public function definition(): D
    {
        return new D(
            code: 'student.active_without_current_placement',
            area: 'Student records',
            title: 'Active Students with no class this year',
            condition: 'The Student is active, but has no active class placement in the current academic year. A Student placed only for a later academic year (for example a new admission for next year) is shown with that placement.',
            whyItMatters: 'Registers, class rosters and fee assessment runs all start from class placements, so these Students are left out of them. This is often a newly added Student who has not been placed yet.',
            severity: D::REVIEW,
            kind: D::REVIEW_RECOMMENDED,
            viewCapabilities: ['students.view', 'enrollments.view'],
            repair: 'Place the Student in a Section (Student page, "Enroll"), or mark the Student inactive if they have left.',
        );
    }

    public function hasFindings(School $school): bool
    {
        $yearId = $this->years->tryResolve($school)?->id;

        return $yearId !== null && $this->query($school, $yearId)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $year = $this->years->tryResolve($school);
        if ($year === null) {
            return new Paginator([], 0, $perPage, $page);
        }

        $students = $this->query($school, $year->id)
            ->select(self::STUDENT_COLUMNS)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
        // SIS 8.1 (DH-12): a placement in a later (draft) year is named, so a
        // Student admitted for next year never reads as "no class" at all.
        $later = StudentEnrollment::query()->where('school_id', $school->id)
            ->whereIn('student_id', collect($students->items())->pluck('id')->all())
            ->where('status', 'active')
            ->whereHas('academicYear', fn ($q) => $q->where('school_id', $school->id)->where('status', 'draft'))
            ->with(['academicYear', 'gradeLevel', 'section'])
            ->orderBy('starts_on')->get()
            ->groupBy('student_id');

        return $students
            ->through(fn (Student $s) => new DataHealthFinding(
                entityType: 'student',
                entityId: $s->id,
                label: self::studentLabel($s),
                detail: "Active · no class in {$year->name}".$later->get($s->id, collect())->map(
                    fn (StudentEnrollment $e) => ' · placed for '.$e->academicYear->name.' ('.trim(implode(' · ', array_filter([$e->gradeLevel?->name, $e->section?->name]))).')',
                )->implode(''),
                links: [
                    new DataHealthLink('Open Student', "/app/students/{$s->id}", ['students.view']),
                    new DataHealthLink('Enroll', "/app/students/{$s->id}/enrollments/create", ['students.view', 'enrollments.manage'], isRepair: true),
                ],
            ));
    }

    /** @return Builder<Student> */
    private function query(School $school, string $yearId)
    {
        return Student::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            // ORG.7D-2: the canonical current-placement rule, not a copy of it.
            ->whereDoesntHave('enrollments', fn ($q) => $this->placements->constrainToCurrentPlacement($q->where('school_id', $school->id), $yearId));
    }
}
