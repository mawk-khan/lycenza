<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\School;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SIS 11.7 / 11.8: the ONE roster query of Student communities -- CURRENT
 * members of a House, Club or Activity, as the viewer may see them (the
 * type's view capability, Campus-aware through StudentAccess: a Campus
 * reader lists only Students whose current Campus is in scope, never the
 * School-wide group). Class columns need `enrollments.view`.
 *
 * Operational listing only: no count by gender or any attribute, no
 * percentage, chart or demographic column. Browser-only (no export or print:
 * SIS 11.8 §55 review). Bounded: one page query, one placement read, one
 * responsibility read.
 */
abstract class StudentGroupRosterReport implements StudentReport
{
    /** @var array<string, string> sort key => fixed ORDER BY over m (membership), g (group), s (student) */
    public const array SORTS = [];

    public function __construct(
        protected readonly TenantContext $context,
        protected readonly StudentAccess $access,
    ) {}

    abstract protected function type(): StudentGroupType;

    /** The view capability of the type. */
    abstract protected function authority(): string;

    /** The filter naming one group (`house_id`, `club_id`, `activity_id`). */
    protected function groupFilter(): string
    {
        return $this->type()->value.'_id';
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        return $this->context->withSchool($school, fn (): array => [
            $this->groupFilter() => StudentGroup::query()->where('school_id', $school->id)->where('group_type', $this->type()->value)
                ->orderBy('display_order')->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (StudentGroup $g) => ['value' => (string) $g->id, 'label' => $g->status === 'active' ? $g->name : "{$g->name} (inactive)"])->all(),
            ...match ($this->type()) {
                StudentGroupType::HOUSE => ['responsibility' => HouseResponsibility::options()],
                StudentGroupType::CLUB => ['responsibility' => ClubResponsibility::options()],
                StudentGroupType::ACTIVITY => ['activity_category' => ActivityCategory::options()],
            },
        ]);
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $viewer, $filters, $sort, $page): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $viewer, $filters)
                ->select(['m.id', 'm.student_id', 'm.starts_on', 'g.name as group_name', 'g.status as group_status', 'g.activity_category', 's.first_name', 's.middle_name', 's.last_name', 's.student_number'])
                ->orderByRaw(static::SORTS[$sort] ?? array_values(static::SORTS)[0])
                ->paginate($this->definition()->perPage, ['*'], 'page', max(1, $page));

            $ids = $raw->getCollection()->pluck('student_id')->map(fn ($id) => (string) $id)->all();
            $placementColumn = collect($this->definition()->columns)->firstWhere('key', 'placement');
            $placements = $placementColumn !== null && $viewer->maySee($placementColumn, StudentReportDefinition::CAMPUS_AWARE)
                ? $this->access->currentPlacements($school, $viewer->scope('enrollments.view'), $ids) : collect();
            /** @var array<string, list<string>> $labels */
            $labels = $this->type() === StudentGroupType::ACTIVITY ? [] : DB::table('student_group_responsibilities')
                ->whereIn('student_group_membership_id', $raw->getCollection()->pluck('id')->all())
                ->whereNull('ends_on')->orderBy('responsibility')->get(['student_group_membership_id', 'responsibility'])
                ->groupBy('student_group_membership_id')
                ->map(fn ($group) => $group->map(fn (object $x): string => (HouseResponsibility::tryFrom($x->responsibility) ?? ClubResponsibility::from($x->responsibility))->label())->values()->all())->all();
            $profile = $viewer->holdsWholeSchool('students.view');

            return $raw->through(fn (object $r): array => $this->present($r, $placements->get((string) $r->student_id), $labels[(string) $r->id] ?? [], $profile));
        });
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->query($school, $viewer, [])->exists());
    }

    /**
     * @param  list<string>  $responsibilities
     * @return array<string, mixed>
     */
    private function present(object $r, ?StudentEnrollment $p, array $responsibilities, bool $profile): array
    {
        return [
            'key' => (string) $r->id,
            'student' => [
                'name' => trim(implode(' ', array_filter([$r->first_name, $r->middle_name, $r->last_name], fn ($v) => $v !== null && $v !== ''))),
                'studentNumber' => (string) $r->student_number,
                'url' => $profile ? '/app/students/'.$r->student_id : null,
            ],
            $this->type()->value => $r->group_status === 'active' ? $r->group_name : "{$r->group_name} (inactive)",
            'placement' => $p === null ? null : [
                'academicYear' => $p->academicYear->name, 'campus' => $p->campus->name, 'gradeLevel' => $p->gradeLevel->name, 'section' => $p->section->name,
            ],
            ...($this->type() === StudentGroupType::ACTIVITY
                ? ['category' => $r->activity_category === null ? '—' : ActivityCategory::from($r->activity_category)->label()]
                : ['responsibility' => $responsibilities === [] ? '—' : implode(', ', $responsibilities)]),
            'since' => substr((string) $r->starts_on, 0, 10),
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function query(School $school, StudentReportViewer $viewer, array $filters): Builder
    {
        // Students the viewer may see (Campus scope in SQL), narrowed by the
        // current placement (enrollments.view scope) and the name search.
        $students = $this->access->students($school, $viewer->scope($this->authority()));
        $students = $this->access->narrowToCurrentPlacement($students, $school, $viewer->scope('enrollments.view'), $filters);
        if (($filters['q'] ?? '') !== '' && $filters['q'] !== null) {
            StudentSearch::anyField($students, (string) $filters['q']);
        }

        $query = DB::table('student_group_memberships as m')
            ->join('student_groups as g', 'g.id', '=', 'm.student_group_id')
            ->join('students as s', 's.id', '=', 'm.student_id')
            ->where('m.school_id', $school->id)->where('m.group_type', $this->type()->value)->whereNull('m.ends_on')
            ->whereIn('m.student_id', $students->select('students.id'));

        $group = $filters[$this->groupFilter()] ?? null;
        if (is_string($group) && $group !== '') {
            Str::isUuid($group) ? $query->where('m.student_group_id', $group) : $query->whereRaw('1 = 0');
        }
        $responsibility = match ($this->type()) {
            StudentGroupType::HOUSE => HouseResponsibility::tryFrom((string) ($filters['responsibility'] ?? '')),
            StudentGroupType::CLUB => ClubResponsibility::tryFrom((string) ($filters['responsibility'] ?? '')),
            StudentGroupType::ACTIVITY => null,
        };
        if ($responsibility !== null) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('student_group_responsibilities as r')
                ->whereColumn('r.student_group_membership_id', 'm.id')->whereNull('r.ends_on')->where('r.responsibility', $responsibility->value));
        }
        $category = $this->type() === StudentGroupType::ACTIVITY ? ActivityCategory::tryFrom((string) ($filters['activity_category'] ?? '')) : null;
        if ($category !== null) {
            $query->where('g.activity_category', $category->value);
        }

        return $query;
    }
}
