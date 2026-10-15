<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Student marked `inactive` who still has an `active` Enrollment in a
 * year that is not closed. Student status and placement are deliberately
 * independent (PHASE-1E-0 section 3), and section 13.1 named this exact
 * state a "data-quality smell, not a hard inconsistency" -- so it is
 * surfaced as Review only, never as an error.
 */
class InactiveStudentWithActiveEnrollmentCheck implements DataHealthCheck
{
    use PresentsStudentFindings;

    public function definition(): D
    {
        return new D(
            code: 'student.inactive_with_active_placement',
            area: 'Student records',
            title: 'Inactive Students who still have an active placement',
            condition: 'The Student is marked inactive, but still has an active class placement in an open academic year.',
            whyItMatters: 'Class lists and registers use the placement, not the Student status, so an inactive Student can still appear on them. Usually either the placement should have been ended, or the Student should not have been marked inactive.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['students.view', 'enrollments.view'],
            repair: 'On the Student page, withdraw or complete the placement, or mark the Student active again. Both are existing actions.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->query($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($school)
            ->select(self::STUDENT_COLUMNS)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Student $s) => new DataHealthFinding(
                entityType: 'student',
                entityId: $s->id,
                label: self::studentLabel($s),
                detail: 'Marked inactive · still has an active placement',
                links: [
                    new DataHealthLink('Open Student', "/app/students/{$s->id}", ['students.view']),
                ],
            ));
    }

    /** @return Builder<Student> */
    private function query(School $school)
    {
        return Student::query()
            ->where('school_id', $school->id)
            ->where('status', 'inactive')
            ->whereHas('enrollments', fn ($q) => $q->where('school_id', $school->id)->where('status', 'active')
                ->whereHas('academicYear', fn ($y) => $y->where('school_id', $school->id)->where('status', '!=', 'closed')));
    }
}
