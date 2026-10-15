<?php

namespace App\Domain\Timetable\Application\DataHealth;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * An `active` Section of the `active` AcademicYear with no `active`
 * TimetableEntry. A Student attendance register is always taken against
 * a scheduled class (docs/modules/ATTENDANCE.md: one register per
 * TimetableEntry and date), so such a Section cannot have attendance
 * taken. Review, not an error: a School may not use the timetable for
 * every Section yet.
 */
class SectionWithoutScheduleCheck implements DataHealthCheck
{
    public function definition(): D
    {
        return new D(
            code: 'timetable.section_without_schedule',
            area: 'Timetable',
            title: 'Sections with no timetable this year',
            condition: 'An active Section of the active academic year has no scheduled classes on the timetable.',
            whyItMatters: 'Attendance registers are taken for scheduled classes, so no register can be taken for this Section until its classes are on the timetable.',
            severity: D::REVIEW,
            kind: D::REVIEW_RECOMMENDED,
            viewCapabilities: ['timetable.schedule.view'],
            repair: 'Schedule the Section\'s classes in Timetable → Schedule a class.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->query($school)->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($school)
            ->with(['gradeLevel', 'academicYear'])
            ->orderByRaw('upper(code)')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Section $s) => new DataHealthFinding(
                entityType: 'section',
                entityId: $s->id,
                label: "{$s->name} ({$s->code})",
                detail: trim(implode(' · ', array_filter([$s->academicYear?->name, $s->gradeLevel?->name, 'no scheduled classes']))),
                links: [
                    new DataHealthLink('Timetable', '/app/timetable-schedule', ['timetable.schedule.view']),
                    new DataHealthLink('Schedule a class', '/app/timetable-schedule/create', ['timetable.schedule.manage'], isRepair: true),
                ],
            ));
    }

    /** @return Builder<Section> */
    private function query(School $school)
    {
        return Section::query()
            ->where('sections.school_id', $school->id)
            ->where('sections.status', 'active')
            ->whereHas('academicYear', fn ($q) => $q->where('school_id', $school->id)->where('status', 'active'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('timetable_entries')
                ->whereColumn('timetable_entries.section_id', 'sections.id')
                ->where('timetable_entries.school_id', $school->id)
                ->where('timetable_entries.status', 'active'));
    }
}
