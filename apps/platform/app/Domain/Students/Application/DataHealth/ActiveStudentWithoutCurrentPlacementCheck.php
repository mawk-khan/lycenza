<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Students\Infrastructure\Student;
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

    public function __construct(private readonly CurrentAcademicYearResolver $years) {}

    public function definition(): D
    {
        return new D(
            code: 'student.active_without_current_placement',
            area: 'Student records',
            title: 'Active Students with no class this year',
            condition: 'The Student is active, but has no active class placement in the current academic year.',
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
        $yearId = $this->years->tryResolve($school)?->id;
        if ($yearId === null) {
            return new Paginator([], 0, $perPage, $page);
        }

        return $this->query($school, $yearId)
            ->select(self::STUDENT_COLUMNS)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Student $s) => new DataHealthFinding(
                entityType: 'student',
                entityId: $s->id,
                label: self::studentLabel($s),
                detail: 'Active · no class placement in the current academic year',
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
            ->whereDoesntHave('enrollments', fn ($q) => $q->where('school_id', $school->id)
                ->where('academic_year_id', $yearId)->where('status', 'active'));
    }
}
