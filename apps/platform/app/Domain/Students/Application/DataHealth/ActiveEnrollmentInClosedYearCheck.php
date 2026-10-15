<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * An `active` Enrollment in an AcademicYear that is `closed`. Closing a
 * year (directly, or by activating the next one, CLAUDE.md rule 65) never
 * ends its placements, and rollover execution never ends the source
 * Enrollment (EnrollmentRolloverItemExecutionService), so last year's
 * placements stay `active` until staff complete them one by one.
 */
class ActiveEnrollmentInClosedYearCheck implements DataHealthCheck
{
    use PresentsStudentFindings;

    public function definition(): D
    {
        return new D(
            code: 'enrollment.active_in_closed_year',
            area: 'Student placement',
            title: 'Placement still open in a closed academic year',
            condition: 'A Student\'s class placement is still marked active, but its academic year has been closed.',
            whyItMatters: 'The year is over, so the placement should have been completed or withdrawn. While it stays active, the Student\'s record shows them still in last year\'s class, and the Student\'s exit date for record keeping cannot be worked out from a placement that never ended.',
            severity: D::ACTION_REQUIRED,
            kind: D::INCONSISTENT,
            viewCapabilities: ['enrollments.view'],
            repair: 'Complete the placement (it ran to the end of its year), or withdraw it if the Student left earlier. Do this from the Student\'s Enrollment history, or complete a whole closed year\'s open placements at once from "Open placements in closed years" (V11.3).',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->query($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($school)
            ->with(['student:'.implode(',', self::STUDENT_COLUMNS), 'academicYear', 'gradeLevel', 'section'])
            ->orderBy('starts_on')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (StudentEnrollment $e) => new DataHealthFinding(
                entityType: 'student_enrollment',
                entityId: $e->id,
                label: self::studentLabel($e->student),
                detail: self::placementDetail($e),
                links: [
                    new DataHealthLink('View placement', self::enrollmentDirectoryUrl($e), ['enrollments.view']),
                    new DataHealthLink('Open Student', "/app/students/{$e->student_id}", ['students.view']),
                    new DataHealthLink('Complete or withdraw', "/app/students/{$e->student_id}#enrollment-history", ['students.view', 'enrollments.manage'], isRepair: true),
                    new DataHealthLink(
                        'Complete this year\'s open placements',
                        '/app/enrollments/closed-year-placements?academic_year_id='.$e->academic_year_id,
                        ['enrollments.manage'],
                        isRepair: true,
                    ),
                ],
            ));
    }

    /** @return Builder<StudentEnrollment> */
    private function query(School $school)
    {
        return StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereHas('academicYear', fn ($q) => $q->where('school_id', $school->id)->where('status', 'closed'));
    }
}
