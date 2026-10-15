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
 * An `active` Enrollment (in a year that is not closed -- a closed year is
 * enrollment.active_in_closed_year) whose Section has been deactivated.
 * Deactivation stops new placements but, by design, does not end existing
 * ones (docs/modules/ACADEMIC-STRUCTURE.md "deactivate, never delete"), so
 * this is legal but almost always unintended.
 */
class ActiveEnrollmentInInactiveSectionCheck implements DataHealthCheck
{
    use PresentsStudentFindings;

    public function definition(): D
    {
        return new D(
            code: 'enrollment.active_in_inactive_section',
            area: 'Student placement',
            title: 'Students placed in a deactivated Section',
            condition: 'A Student\'s active class placement is in a Section that has been deactivated.',
            whyItMatters: 'A deactivated Section is no longer offered for new work, so these Students may be left out of class lists and timetables staff expect to use.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['enrollments.view'],
            repair: 'Transfer the Student to an active Section (Student page, current year), or reactivate the Section in School setup if it was deactivated by mistake.',
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
            ->orderBy('section_id')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (StudentEnrollment $e) => new DataHealthFinding(
                entityType: 'student_enrollment',
                entityId: $e->id,
                label: self::studentLabel($e->student),
                detail: self::placementDetail($e).' · Section deactivated',
                links: [
                    new DataHealthLink('View placement', self::enrollmentDirectoryUrl($e), ['enrollments.view']),
                    new DataHealthLink('Transfer from the Student page', "/app/students/{$e->student_id}", ['students.view', 'enrollments.manage'], isRepair: true),
                    new DataHealthLink('Sections', '/app/school-setup/sections', ['academics.structure.manage'], isRepair: true),
                ],
            ));
    }

    /** @return Builder<StudentEnrollment> */
    private function query(School $school)
    {
        return StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereHas('section', fn ($q) => $q->where('school_id', $school->id)->where('status', 'inactive'))
            ->whereHas('academicYear', fn ($q) => $q->where('school_id', $school->id)->where('status', '!=', 'closed'));
    }
}
