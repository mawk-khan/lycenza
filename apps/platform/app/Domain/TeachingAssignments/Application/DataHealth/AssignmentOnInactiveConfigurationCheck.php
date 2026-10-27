<?php

namespace App\Domain\TeachingAssignments\Application\DataHealth;

use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A current or upcoming TeachingAssignment (no end date, or ending today
 * or later in the School's calendar) whose Section or Subject Offering
 * has been deactivated. Deactivating either does not end the assignment,
 * so a teacher can keep owning a class context the School no longer
 * offers. Elective (Offering-wide) assignments are a separate table and
 * are not covered by this first check.
 */
class AssignmentOnInactiveConfigurationCheck implements DataHealthCheck
{
    public function __construct(private readonly TeachingAssignmentReadService $reads) {}

    public function definition(): D
    {
        return new D(
            code: 'teaching.assignment_on_inactive_class',
            area: 'Teaching assignments',
            title: 'Teaching assignments on a deactivated class',
            condition: 'A current or upcoming teaching assignment is for a Section or subject offering that has been deactivated.',
            whyItMatters: 'The teacher still owns a class the School no longer runs, which keeps it in their own pages and leaves the class without a valid owner if it is replaced.',
            severity: D::REVIEW,
            kind: D::INCONSISTENT,
            viewCapabilities: ['teaching.assignments.view'],
            repair: 'End the assignment in Teaching assignments (and create one for the class that replaced it), or reactivate the Section or subject offering if it was deactivated by mistake.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->query($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($school)
            ->with(['employee', 'section.gradeLevel', 'subjectOffering.subject'])
            ->orderBy('starts_on')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(function (TeachingAssignment $a) {
                $url = '/app/teaching-assignments?'.http_build_query(['academic_year_id' => $a->academic_year_id, 'employee_id' => $a->employee_id]);
                $inactive = array_filter([
                    $a->section?->status === 'inactive' ? 'Section deactivated' : null,
                    $a->subjectOffering?->status === 'inactive' ? 'subject offering deactivated' : null,
                ]);

                return new DataHealthFinding(
                    entityType: 'teaching_assignment',
                    entityId: $a->id,
                    label: "{$a->employee?->full_name} ({$a->employee?->employee_number})",
                    detail: trim(implode(' · ', array_filter([
                        // SIS 8.1 (DH-18): the Section as Teaching assignments shows it.
                        $a->section !== null ? trim(implode(' · ', array_filter([$a->section->gradeLevel?->name, $a->section->name]))) : null,
                        $a->subjectOffering?->subject?->name,
                        'from '.$a->starts_on->toDateString(),
                        implode(', ', $inactive),
                    ]))),
                    links: [
                        new DataHealthLink('View assignments', $url, ['teaching.assignments.view']),
                        new DataHealthLink('End the assignment', $url, ['teaching.assignments.manage'], isRepair: true),
                    ],
                );
            });
    }

    /** @return Builder<TeachingAssignment> */
    private function query(School $school)
    {
        $today = $this->reads->today($school);

        return TeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            // A voided assignment (ends before it starts, S7) never owned a day.
            ->whereRaw('(ends_on IS NULL OR ends_on >= starts_on)')
            ->where(fn ($q) => $q
                ->whereHas('section', fn ($s) => $s->where('school_id', $school->id)->where('status', 'inactive'))
                ->orWhereHas('subjectOffering', fn ($o) => $o->where('school_id', $school->id)->where('status', 'inactive')));
    }
}
