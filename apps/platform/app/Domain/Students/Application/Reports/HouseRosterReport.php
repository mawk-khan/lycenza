<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\School;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SIS 11.7: the House roster -- CURRENT House members, as the viewer may see
 * them (`students.houses.view`, Campus-aware through StudentAccess: a
 * Campus reader lists only Students whose current Campus is in scope, never
 * the School-wide House). Class columns need `enrollments.view`.
 *
 * Operational listing only: no count by gender or any attribute, no
 * percentage, chart or demographic column. Browser-only for now (no export
 * or print: House membership is relationship information that needs its own
 * export review). Bounded: one page query, one placement read, one
 * responsibility read.
 */
final class HouseRosterReport implements StudentReport
{
    public const array SORTS = [
        'house' => 'g.display_order asc, g.name asc, s.last_name asc nulls last, s.first_name asc, m.id asc',
        'student' => 's.last_name asc nulls last, s.first_name asc, m.id asc',
        'since' => 'm.starts_on desc, m.id asc',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $access,
    ) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.houses',
            title: 'House roster',
            description: 'Students in each House now, with their class and House responsibility.',
            category: 'students',
            owner: 'Students (Houses)',
            authority: [HouseAdministrationService::VIEW],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['house_id', 'q', 'responsibility', 'campus_id', 'grade_level_id', 'section_id'],
            columns: [
                new C('student', 'Student', 'student'),
                new C('house', 'House'),
                new C('placement', 'Class', 'placement', C::RESTRICTED, ['enrollments.view']),
                new C('responsibility', 'Responsibility'),
                new C('since', 'Member since', 'date'),
            ],
            sorts: ['house' => 'House, then Student', 'student' => 'Student name', 'since' => 'Newest members first'],
            perPage: 25,
            reference: ['SR-17'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        return $this->context->withSchool($school, fn (): array => [
            'house_id' => StudentGroup::query()->where('school_id', $school->id)->where('group_type', StudentGroupType::HOUSE->value)
                ->orderBy('display_order')->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (StudentGroup $h) => ['value' => (string) $h->id, 'label' => $h->status === 'active' ? $h->name : "{$h->name} (inactive)"])->all(),
            'responsibility' => HouseResponsibility::options(),
        ]);
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $viewer, $filters, $sort, $page): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $viewer, $filters)
                ->select(['m.id', 'm.student_id', 'm.starts_on', 'g.name as house_name', 'g.status as house_status', 's.first_name', 's.middle_name', 's.last_name', 's.student_number'])
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['house'])
                ->paginate($this->definition()->perPage, ['*'], 'page', max(1, $page));

            $ids = $raw->getCollection()->pluck('student_id')->map(fn ($id) => (string) $id)->all();
            $placements = $viewer->maySee($this->definition()->columns[2], StudentReportDefinition::CAMPUS_AWARE)
                ? $this->access->currentPlacements($school, $viewer->scope('enrollments.view'), $ids) : collect();
            $responsibilities = DB::table('student_group_responsibilities')->whereIn('student_group_membership_id', $raw->getCollection()->pluck('id')->all())
                ->whereNull('ends_on')->orderBy('responsibility')->get(['student_group_membership_id', 'responsibility'])->groupBy('student_group_membership_id');
            $profile = $viewer->holdsWholeSchool('students.view');

            /** @var array<string, list<string>> $labels */
            $labels = $responsibilities->map(fn ($group) => $group->map(fn (object $x): string => HouseResponsibility::from($x->responsibility)->label())->values()->all())->all();

            return $raw->through(fn (object $r): array => $this->present($r, $placements->get((string) $r->student_id), $labels[(string) $r->id] ?? [], $profile));
        });
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
            'house' => $r->house_status === 'active' ? $r->house_name : "{$r->house_name} (inactive)",
            'placement' => $p === null ? null : [
                'academicYear' => $p->academicYear->name, 'campus' => $p->campus->name, 'gradeLevel' => $p->gradeLevel->name, 'section' => $p->section->name,
            ],
            'responsibility' => $responsibilities === [] ? '—' : implode(', ', $responsibilities),
            'since' => substr((string) $r->starts_on, 0, 10),
        ];
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->query($school, $viewer, [])->exists());
    }

    /** @param  array<string, mixed>  $filters */
    private function query(School $school, StudentReportViewer $viewer, array $filters): Builder
    {
        // Students the viewer may see (Campus scope in SQL), narrowed by the
        // current placement (enrollments.view scope) and the name search.
        $students = $this->access->students($school, $viewer->scope(HouseAdministrationService::VIEW));
        $students = $this->access->narrowToCurrentPlacement($students, $school, $viewer->scope('enrollments.view'), $filters);
        if (($filters['q'] ?? '') !== '' && $filters['q'] !== null) {
            StudentSearch::anyField($students, (string) $filters['q']);
        }

        $query = DB::table('student_group_memberships as m')
            ->join('student_groups as g', 'g.id', '=', 'm.student_group_id')
            ->join('students as s', 's.id', '=', 'm.student_id')
            ->where('m.school_id', $school->id)->where('m.group_type', StudentGroupType::HOUSE->value)->whereNull('m.ends_on')
            ->whereIn('m.student_id', $students->select('students.id'));

        $house = $filters['house_id'] ?? null;
        if (is_string($house) && $house !== '') {
            Str::isUuid($house) ? $query->where('m.student_group_id', $house) : $query->whereRaw('1 = 0');
        }
        $responsibility = HouseResponsibility::tryFrom((string) ($filters['responsibility'] ?? ''));
        if ($responsibility !== null) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('student_group_responsibilities as r')
                ->whereColumn('r.student_group_membership_id', 'm.id')->whereNull('r.ends_on')->where('r.responsibility', $responsibility->value));
        }

        return $query;
    }
}
