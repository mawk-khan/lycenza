<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Students\Application\SectionOccupancy;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\StudentReports\Export\StudentReportExportColumn as X;
use App\Support\StudentReports\Export\StudentReportExportDefinition;
use App\Support\StudentReports\Export\StudentReportExporter;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportFilter;
use App\Support\StudentReports\StudentReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * SIS 11.3 (OD-SIS-6): Section capacity and vacancy -- operational state,
 * not analytics. One row per Section: its ADVISORY capacity (Academic
 * Structure), its strength (StudentEnrollmentRosterReadService, the one
 * placement predicate) on the School-local today clamped into its year, and
 * the resulting seats or overage. A closed year's Section shows capacity
 * only.
 *
 * - Campus-aware on `enrollments.view` through StudentAccess::capacitySections()
 *   (a Campus reader: the active year's Sections on their Campuses only; a
 *   forged Campus or year matches nothing).
 * - Never a percentage, average, trend, time series or breakdown; never a
 *   Student name (the row links to the placements of that Section, which
 *   re-checks its own authority).
 * - Bounded: one count and one page query (the strength is a grouped
 *   sub-query joined in SQL, so state filters and sorts stay in SQL).
 */
final class SectionCapacityReport implements StudentReport, StudentReportExporter
{
    /** The SQL form of SectionOccupancy::state() (pinned equal by SectionCapacityTest). */
    private const string STATE_SQL = "CASE WHEN st.section_id IS NULL THEN 'closed_year'
        WHEN sections.capacity IS NULL THEN 'unconfigured'
        WHEN st.strength < sections.capacity THEN 'available'
        WHEN st.strength = sections.capacity THEN 'at_capacity'
        ELSE 'over_capacity' END";

    public const array SORTS = [
        'section' => 'ay.starts_on desc, g.sequence asc, sections.name asc, c.name asc, sections.id asc',
        'available' => '(sections.capacity - st.strength) asc nulls last, ay.starts_on desc, g.sequence asc, sections.name asc, sections.id asc',
        'strength' => 'st.strength desc nulls last, ay.starts_on desc, g.sequence asc, sections.name asc, sections.id asc',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentAccess $access,
        private readonly StudentEnrollmentRosterReadService $roster,
    ) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.section_capacity',
            title: 'Section capacity',
            description: 'Each Section\'s advisory capacity against the Students placed in it today, with seats available or the overage.',
            category: 'enrollment',
            owner: 'Academic Structure (capacity) · Students (strength)',
            authority: ['enrollments.view'],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['academic_year_id', 'campus_id', 'grade_level_id', 'status'],
            columns: [
                new C('section', 'Section', 'student'),
                new C('campus', 'Campus'),
                new C('academic_year', 'Academic year'),
                new C('occupancy', 'Students / capacity'),
                new C('seats', 'Seats'),
                new C('as_of', 'Counted on', 'date'),
            ],
            sorts: ['section' => 'Year, Grade and Section', 'available' => 'Fewest seats first', 'strength' => 'Most Students first'],
            perPage: 25,
            reference: ['SR-24'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        $scope = $viewer->scope('enrollments.view');
        $sections = $this->access->capacitySections($school, $scope);

        return $this->context->withSchool($school, fn (): array => [
            'academic_year_id' => AcademicYear::query()->whereIn('id', (clone $sections)->select('sections.academic_year_id'))
                ->orderByDesc('starts_on')->get(['id', 'name', 'status'])
                ->map(fn (AcademicYear $y) => ['value' => (string) $y->id, 'label' => $y->name.($y->status === 'closed' ? ' (closed)' : ($y->status === 'draft' ? ' (draft)' : ''))])->all(),
            'campus_id' => Campus::query()->whereIn('id', (clone $sections)->select('sections.campus_id'))->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (Campus $c) => ['value' => (string) $c->id, 'label' => $c->status === 'active' ? $c->name : "{$c->name} (inactive)"])->all(),
            'grade_level_id' => GradeLevel::query()->whereIn('id', (clone $sections)->select('sections.grade_level_id'))->orderBy('sequence')->get(['id', 'name'])
                ->map(fn (GradeLevel $g) => ['value' => (string) $g->id, 'label' => $g->name])->all(),
            'status' => array_map(fn (string $s) => ['value' => $s, 'label' => SectionOccupancy::STATE_LABELS[$s]], [
                SectionOccupancy::AVAILABLE, SectionOccupancy::AT_CAPACITY, SectionOccupancy::OVER_CAPACITY, SectionOccupancy::UNCONFIGURED,
            ]),
        ]);
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        $scope = $viewer->scope('enrollments.view');

        return $this->context->withSchool($school, function () use ($school, $scope, $filters, $sort, $page): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $scope, $filters)
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['section'])
                ->paginate($this->definition()->perPage, ['*'], 'page', max(1, $page));

            return $raw->through(fn (object $row): array => $this->present($row));
        });
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->query($school, $viewer->scope('enrollments.view'), [])->exists());
    }

    // --- SIS 11.4 (D-11): the governed export, on the same query ------------

    public function exportDefinition(): StudentReportExportDefinition
    {
        return new StudentReportExportDefinition('students.export', [
            new X('academic_year', 'Academic year'),
            new X('campus', 'Campus'),
            new X('section', 'Section'),
            new X('capacity', 'Advisory capacity'),
            new X('students', 'Students'),
            new X('seats', 'Seats'),
            new X('counted_on', 'Counted on'),
        ], 'section-capacity');
    }

    public function filterRules(School $school, StudentReportViewer $viewer): array
    {
        return StudentReportFilter::rules($this->definition()->filters, $this->options($school, $viewer), array_keys($this->definition()->sorts));
    }

    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int
    {
        return $this->context->withSchool($school, fn (): int => $this->query($school, $viewer->scope('enrollments.view'), $this->values($filters))->count());
    }

    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable
    {
        $order = self::SORTS[$filters['sort'] ?? 'section'] ?? self::SORTS['section'];
        for ($page = 1; ; $page++) {
            $batch = $this->context->withSchool($school, fn (): array => $this->query($school, $viewer->scope('enrollments.view'), $this->values($filters))
                ->orderByRaw($order)->forPage($page, 500)->get()->map(fn (object $row): array => $this->present($row))->all());
            foreach ($batch as $r) {
                yield [
                    'academic_year' => $r['academic_year'],
                    'campus' => $r['campus'],
                    'section' => $r['section']['name'],
                    'capacity' => $r['capacity'],
                    'students' => $r['strength'],
                    'seats' => $r['seats'],
                    'counted_on' => $r['as_of'],
                ];
            }
            if (count($batch) < 500) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function values(array $validated): array
    {
        return array_map(fn ($v) => (string) $v, array_filter(array_intersect_key($validated, array_flip($this->definition()->filters)), fn ($v) => $v !== null && $v !== ''));
    }

    /** @param  array<string, string>  $filters */
    private function query(School $school, CampusScope $scope, array $filters): Builder
    {
        $s = $school->id;
        $query = $this->access->capacitySections($school, $scope)->toBase()
            ->join('academic_years as ay', fn ($j) => $j->on('ay.id', '=', 'sections.academic_year_id')->where('ay.school_id', '=', $s))
            ->join('campuses as c', fn ($j) => $j->on('c.id', '=', 'sections.campus_id')->where('c.school_id', '=', $s))
            ->join('grade_levels as g', fn ($j) => $j->on('g.id', '=', 'sections.grade_level_id')->where('g.school_id', '=', $s))
            ->leftJoinSub($this->roster->sectionStrengthQuery($s, SchoolTimezone::today($school)), 'st', 'st.section_id', '=', 'sections.id')
            ->select([
                'sections.id', 'sections.name', 'sections.capacity', 'sections.status', 'sections.academic_year_id',
                'ay.name as year_name', 'ay.status as year_status', 'c.name as campus_name', 'c.status as campus_status',
                'g.name as grade_name', 'st.strength', 'st.as_of',
            ])
            ->selectRaw(self::STATE_SQL.' AS state');

        foreach (['academic_year_id' => 'sections.academic_year_id', 'campus_id' => 'sections.campus_id', 'grade_level_id' => 'sections.grade_level_id'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if (($filters['status'] ?? '') !== '') {
            $query->whereRaw(self::STATE_SQL.' = ?', [$filters['status']]);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $occupancy = new SectionOccupancy(
            $row->capacity === null ? null : (int) $row->capacity,
            $row->strength === null ? null : (int) $row->strength,
            $row->as_of === null ? null : substr((string) $row->as_of, 0, 10),
        );
        $label = ($row->grade_name !== null ? "{$row->grade_name} · " : '').$row->name;

        return [
            'key' => (string) $row->id,
            'section' => [
                'name' => $row->status === 'active' ? $label : "{$label} (inactive)",
                // The placements of this Section (the Enrollment directory re-checks its own authority).
                'url' => '/app/enrollments?'.http_build_query(['academic_year_id' => $row->academic_year_id, 'section_id' => $row->id]),
            ],
            'campus' => $row->campus_status === 'active' ? $row->campus_name : "{$row->campus_name} (inactive)",
            'academic_year' => $row->year_name,
            'occupancy' => match ($occupancy->state()) {
                SectionOccupancy::CLOSED_YEAR => $occupancy->capacity === null ? '—' : "Capacity {$occupancy->capacity}",
                SectionOccupancy::UNCONFIGURED => SectionOccupancy::students($occupancy->strength),
                default => "{$occupancy->strength} / {$occupancy->capacity}",
            },
            'seats' => match ($occupancy->state()) {
                SectionOccupancy::AVAILABLE => SectionOccupancy::seats($occupancy->available()),
                SectionOccupancy::OVER_CAPACITY => 'Over advisory capacity by '.abs((int) $occupancy->available()),
                SectionOccupancy::CLOSED_YEAR => 'Closed year (capacity only)',
                default => SectionOccupancy::STATE_LABELS[$occupancy->state()],
            },
            'as_of' => $occupancy->asOf,
            'state' => $row->state,
            'capacity' => $occupancy->capacity,
            'strength' => $occupancy->strength,
        ];
    }
}
