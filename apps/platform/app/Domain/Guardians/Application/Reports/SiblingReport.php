<?php

namespace App\Domain\Guardians\Application\Reports;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Search\NameSearch;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.2 (SR-25, owner decision 2026-10-31 on SIS-019 / PV1-17): the
 * School-wide sibling list -- names only.
 *
 * A family is one ACTIVE Guardian linked to two or more Students of the
 * School: exactly StudentSiblingReader's rule (siblings share an active
 * Guardian; flags do not matter), never a household table. Two Guardians
 * of the same children are two rows, as on the reference.
 *
 * - Whole School only, `students.view` AND `guardians.view` (rule 118:
 *   siblings never on a Campus surface). Each Student's current class, and
 *   the current-class filters, also need whole-School `enrollments.view`;
 *   without it the class is absent and a class filter matches nothing.
 * - Minimal: Guardian and Student names, Student numbers and status. Never
 *   a contact, relationship flag, date of birth, address, residential
 *   category, dues (D-14) or a count beyond the paginator's own total.
 * - Bounded: one count and one page query for the families, then one query
 *   for their Students and one eager-loaded current-placement read.
 */
final class SiblingReport implements StudentReport
{
    public const array SORTS = [
        'guardian' => 'g.last_name asc nulls first, g.first_name asc, g.id asc',
        'guardian_desc' => 'g.last_name desc nulls last, g.first_name desc, g.id desc',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $students,
        private readonly CurrentAcademicYearResolver $years,
        private readonly StudentEnrollmentReadService $enrollments,
    ) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.siblings',
            title: 'Siblings',
            description: 'Students who share an active Guardian, one row per Guardian. Names and current class only.',
            category: 'students',
            owner: 'Guardians (sibling rule)',
            authority: ['students.view', 'guardians.view'],
            scope: StudentReportDefinition::WHOLE_SCHOOL,
            route: StudentReportDefinition::RENDERED,
            filters: ['q', 'campus_id', 'grade_level_id', 'section_id'],
            columns: [
                new C('guardian', 'Guardian', 'student'),
                new C('students', 'Students', 'student_list'),
            ],
            sorts: ['guardian' => 'Guardian name (A–Z)', 'guardian_desc' => 'Guardian name (Z–A)'],
            perPage: 25,
            reference: ['SR-25'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        $year = $viewer->holdsWholeSchool('enrollments.view') ? $this->years->tryResolve($school) : null;
        if ($year === null) {
            return ['campus_id' => [], 'grade_level_id' => [], 'section_id' => []];
        }

        // The current year's Sections that can hold a current placement (SIS.HARDEN-1 R-5).
        $sections = Section::query()->where('sections.school_id', $school->id)->where('sections.academic_year_id', $year->id)
            ->tap(fn ($q) => $this->enrollments->matchableSections($q, $year->id))
            ->with(['campus:id,name,status', 'gradeLevel:id,name,sequence'])->get()
            ->sortBy(fn (Section $s) => sprintf('%08d|%s|%s', $s->gradeLevel->sequence, $s->name, $s->campus->name))->values();

        return [
            'campus_id' => $sections->pluck('campus')->unique('id')->sortBy('name')->map(fn (Campus $c) => [
                'value' => (string) $c->id, 'label' => $c->status === 'active' ? $c->name : "{$c->name} (inactive)",
            ])->values()->all(),
            'grade_level_id' => $sections->pluck('gradeLevel')->unique('id')->sortBy('sequence')
                ->map(fn (GradeLevel $g) => ['value' => (string) $g->id, 'label' => $g->name])->values()->all(),
            'section_id' => $sections->map(fn (Section $s) => [
                'value' => (string) $s->id,
                'label' => SectionLabel::of($s).' · '.$s->campus->name.($s->status === 'active' ? '' : ' (inactive)'),
                // Narrowed by the chosen Campus and Grade on the page (no impossible pair).
                'campus_id' => (string) $s->campus_id,
                'grade_level_id' => (string) $s->grade_level_id,
            ])->all(),
        ];
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        // The viewer's own resolved placement scope, used only when whole-School.
        $placementScope = $viewer->scope('enrollments.view');
        $classScope = $placementScope->isWholeSchool() ? $placementScope : null;

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $classScope): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $filters, $classScope)
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['guardian'])
                ->paginate($this->definition()->perPage, ['g.id', 'g.first_name', 'g.middle_name', 'g.last_name'], 'page', max(1, $page));

            $members = $this->members($school, $raw->getCollection()->pluck('id')->all());
            $placements = $classScope === null ? null
                : $this->students->currentPlacements($school, $classScope, $members->flatten(1)->pluck('id')->unique()->values()->all());

            return $raw->through(fn (object $g): array => $this->present($g, $members->get((string) $g->id) ?? collect(), $placements));
        });
    }

    /**
     * @param  Collection<int, \stdClass>  $members
     * @param  \Illuminate\Database\Eloquent\Collection<string, StudentEnrollment>|null  $placements  null without whole-School placement read
     * @return array<string, mixed>
     */
    private function present(object $guardian, Collection $members, ?\Illuminate\Database\Eloquent\Collection $placements): array
    {
        return [
            'key' => (string) $guardian->id,
            'guardian' => ['name' => $this->name($guardian), 'url' => '/app/guardians/'.$guardian->id],
            'students' => $members->map(fn (object $s): array => [
                'name' => $this->name($s),
                'studentNumber' => (string) $s->student_number,
                'url' => '/app/students/'.$s->id,
                'inactive' => $s->status !== 'active',
                'placement' => $placements === null ? null : $this->placement($placements->get((string) $s->id)),
            ])->values()->all(),
        ];
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->query($school, [], null)->exists());
    }

    /**
     * The families: active Guardians of this School linked to two or more
     * Students (one grouped scan of the relationships, never a per-row count).
     *
     * @param  array<string, string>  $filters
     * @param  ?CampusScope  $classScope  the viewer's whole-School `enrollments.view` scope, or null
     */
    private function query(School $school, array $filters, ?CampusScope $classScope): Builder
    {
        $s = $school->id;
        $families = DB::table('student_guardian_relationships')->where('school_id', $s)
            ->groupBy('guardian_id')->havingRaw('count(*) >= 2')->select('guardian_id');

        $query = DB::table('guardians as g')
            ->joinSub($families, 'f', 'f.guardian_id', '=', 'g.id')
            ->where('g.school_id', $s)
            ->where('g.status', 'active');

        if (trim($filters['q'] ?? '') !== '') {
            $term = $filters['q'];
            // The Guardian's name, or any linked Student's name or number (the shared search rules).
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $n) => NameSearch::everyWord($n, $term, ['g.first_name', 'g.middle_name', 'g.last_name']))
                ->orWhereIn('g.id', $this->guardiansOf($s, fn (Builder $st) => StudentSearch::anyField($st, $term, 'st'))));
        }

        $placement = array_filter(array_intersect_key($filters, array_flip(['campus_id', 'grade_level_id', 'section_id'])), fn ($v) => $v !== '');
        if ($placement !== []) {
            if ($classScope === null) {
                // The current class is enrollments data this viewer may not read.
                return $query->whereRaw('1 = 0');
            }
            $current = $this->students->narrowToCurrentPlacement(Student::query()->where('students.school_id', $s), $school, $classScope, $placement)
                ->select('students.id')->toBase();
            $query->whereIn('g.id', $this->guardiansOf($s, fn (Builder $st) => $st->whereIn('st.id', $current)));
        }

        return $query;
    }

    /** The Guardians linked to a Student matching $where (a sub-query). */
    private function guardiansOf(string $schoolId, \Closure $where): Builder
    {
        $students = DB::table('students as st')->where('st.school_id', $schoolId)->select('st.id');
        $where($students);

        return DB::table('student_guardian_relationships as r')->where('r.school_id', $schoolId)
            ->whereIn('r.student_id', $students)->select('r.guardian_id');
    }

    /**
     * The page's families' Students, in ONE query.
     *
     * @param  list<string>  $guardianIds
     * @return Collection<int|string, Collection<int, \stdClass>>
     */
    private function members(School $school, array $guardianIds): Collection
    {
        if ($guardianIds === []) {
            return collect();
        }

        return DB::table('student_guardian_relationships as r')
            ->join('students as st', fn ($j) => $j->on('st.id', '=', 'r.student_id')->where('st.school_id', '=', $school->id))
            ->where('r.school_id', $school->id)
            ->whereIn('r.guardian_id', $guardianIds)
            ->orderBy('st.first_name')->orderBy('st.last_name')->orderBy('st.id')
            ->get(['r.guardian_id', 'st.id', 'st.first_name', 'st.middle_name', 'st.last_name', 'st.student_number', 'st.status'])
            ->groupBy(fn (object $row) => (string) $row->guardian_id);
    }

    /** @return array<string, ?string>|null */
    private function placement(?StudentEnrollment $placement): ?array
    {
        return $placement === null ? null : [
            'academicYear' => $placement->academicYear?->name,
            'campus' => $placement->campus?->name,
            'gradeLevel' => $placement->gradeLevel?->name,
            'section' => $placement->section?->name,
        ];
    }

    private function name(object $person): string
    {
        return trim(implode(' ', array_filter([$person->first_name, $person->middle_name, $person->last_name], fn ($p) => $p !== null && $p !== '')));
    }
}
