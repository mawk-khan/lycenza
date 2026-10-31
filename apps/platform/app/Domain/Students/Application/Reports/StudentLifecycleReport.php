<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\StudentLifecycleReason;
use App\Domain\Students\Application\StudentSearch;
use App\Models\Campus;
use App\Models\School;
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
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.1: the Student lifecycle report -- every governed lifecycle action
 * (ORG.8E `student_lifecycle_events`): a Student marked inactive or active
 * again, a placement withdrawn or cancelled. A read-only projection of that
 * append-only table; one row per event.
 *
 * - Whole School only: lifecycle history spans Campuses and years (rule 101:
 *   a Campus reader sees current placements, never history). Authority is
 *   whole-School `students.view`; placement events (withdrawn, cancelled)
 *   and the placement column also need whole-School `enrollments.view`,
 *   otherwise they are absent (never blanked).
 * - Dates are the School-local calendar date of the recorded action.
 * - A cancellation recorded with ADR 0078's empty interval (`ends_on =
 *   starts_on - 1`) reads "Cancelled before start", never a reversed range.
 * - Minimal: closed codes rendered as labels. Never the acting User (the
 *   audit log, under `school.audit.view`, holds who acted), never free text,
 *   MFA or audit metadata, Guardian, date of birth or Finance data.
 * - Bounded: one count and one page query (names joined in SQL).
 */
final class StudentLifecycleReport implements StudentReport, StudentReportExporter
{
    public const array SORTS = [
        'date_desc' => 'occurred_at desc, id desc',
        'date_asc' => 'occurred_at asc, id asc',
        'student' => 'last_name asc nulls first, first_name asc, student_id asc, occurred_at desc, id desc',
        'action' => 'action asc, occurred_at desc, id desc',
    ];

    public const array ACTION_LABELS = [
        StudentLifecycleReason::DEACTIVATE => 'Marked inactive',
        StudentLifecycleReason::REACTIVATE => 'Marked active again',
        StudentLifecycleReason::WITHDRAW_ENROLLMENT => 'Placement withdrawn',
        StudentLifecycleReason::CANCEL_ENROLLMENT => 'Placement cancelled',
    ];

    public const string CANCELLED_BEFORE_START = 'Cancelled before start';

    private const array PLACEMENT_ACTIONS = [StudentLifecycleReason::WITHDRAW_ENROLLMENT, StudentLifecycleReason::CANCEL_ENROLLMENT];

    public function __construct(private readonly TenantContext $context) {}

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.lifecycle',
            title: 'Student lifecycle',
            description: 'Students marked inactive or active again, and placements withdrawn or cancelled, with the recorded reason.',
            category: 'lifecycle',
            owner: 'Students',
            authority: ['students.view'],
            scope: StudentReportDefinition::WHOLE_SCHOOL,
            route: StudentReportDefinition::RENDERED,
            filters: ['q', 'action', 'reason', 'campus_id', 'from', 'to'],
            columns: [
                new C('occurred_on', 'Date', 'date'),
                new C('student', 'Student', 'student'),
                new C('action', 'Action'),
                new C('reason', 'Reason'),
                new C('placement', 'Placement', 'placement', C::RESTRICTED, ['enrollments.view']),
            ],
            sorts: ['date_desc' => 'Newest first', 'date_asc' => 'Oldest first', 'student' => 'Student name', 'action' => 'Action'],
            perPage: 25,
            reference: ['SR-10', 'SR-12', 'SR-36'],
        );
    }

    public function options(School $school, StudentReportViewer $viewer): array
    {
        $actions = $this->actions($viewer);
        $reasons = array_values(array_unique(array_merge(...array_map(fn (string $a) => StudentLifecycleReason::CODES[$a], $actions))));

        return [
            'action' => array_map(fn (string $a) => ['value' => $a, 'label' => self::ACTION_LABELS[$a]], $actions),
            'reason' => array_map(fn (string $r) => ['value' => $r, 'label' => StudentLifecycleReason::LABELS[$r]], $reasons),
            // Every Campus, active or not: history keeps the Campus it was written on.
            'campus_id' => $viewer->holdsWholeSchool('enrollments.view')
                ? Campus::query()->where('school_id', $school->id)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Campus $c) => ['value' => (string) $c->id, 'label' => $c->name])->all()
                : [],
        ];
    }

    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator
    {
        $definition = $this->definition();
        $placementColumn = $viewer->maySee($definition->columns[4], StudentReportDefinition::WHOLE_SCHOOL);

        return $this->context->withSchool($school, function () use ($school, $viewer, $filters, $sort, $page, $definition, $placementColumn): LengthAwarePaginator {
            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $this->query($school, $viewer, $filters)
                ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['date_desc'])
                ->paginate($definition->perPage, ['*'], 'page', max(1, $page));

            return $raw->through(fn (object $row): array => $this->present($row, $placementColumn));
        });
    }

    public function hasAny(School $school, StudentReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->query($school, $viewer, [])->exists());
    }

    // --- SIS 11.4 (D-11): the governed export, on the same query ------------

    public function exportDefinition(): StudentReportExportDefinition
    {
        return new StudentReportExportDefinition('students.export', [
            new X('recorded_on', 'Recorded on'),
            new X('student_number', 'Student number'),
            new X('student_name', 'Student name'),
            new X('action', 'Action'),
            new X('reason', 'Reason'),
            new X('placement', 'Placement', ['enrollments.view']),
        ], 'student-lifecycle');
    }

    public function filterRules(School $school, StudentReportViewer $viewer): array
    {
        return StudentReportFilter::rules($this->definition()->filters, $this->options($school, $viewer), array_keys($this->definition()->sorts));
    }

    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int
    {
        return $this->context->withSchool($school, fn (): int => $this->query($school, $viewer, $this->filterValues($filters))->count());
    }

    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable
    {
        $placementColumn = $viewer->maySee($this->definition()->columns[4], StudentReportDefinition::WHOLE_SCHOOL);
        $order = self::SORTS[$filters['sort'] ?? 'date_desc'] ?? self::SORTS['date_desc'];
        for ($page = 1; ; $page++) {
            $batch = $this->context->withSchool($school, fn (): array => $this->query($school, $viewer, $this->filterValues($filters))
                ->orderByRaw($order)->forPage($page, 500)->get()
                ->map(fn (object $row): array => $this->present($row, $placementColumn))->all());
            foreach ($batch as $r) {
                yield [
                    'recorded_on' => $r['occurred_on'],
                    'student_number' => $r['student']['studentNumber'],
                    'student_name' => $r['student']['name'],
                    'action' => $r['action'],
                    'reason' => $r['reason'],
                    'placement' => isset($r['placement']) ? Exports\StudentMovementExporter::place($r['placement']) : null,
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
    private function filterValues(array $validated): array
    {
        return array_map(fn ($v) => (string) $v, array_filter(array_intersect_key($validated, array_flip($this->definition()->filters)), fn ($v) => $v !== null && $v !== ''));
    }

    /** @return list<string> the actions this viewer may see */
    private function actions(StudentReportViewer $viewer): array
    {
        $actions = [StudentLifecycleReason::DEACTIVATE, StudentLifecycleReason::REACTIVATE];

        return $viewer->holdsWholeSchool('enrollments.view') ? [...$actions, ...self::PLACEMENT_ACTIONS] : $actions;
    }

    /** @param  array<string, string>  $filters */
    private function query(School $school, StudentReportViewer $viewer, array $filters): Builder
    {
        $s = $school->id;
        $timezone = SchoolTimezone::resolve($school)->getName();

        $query = DB::query()->fromSub(
            DB::table('student_lifecycle_events as le')
                ->join('students as st', fn ($j) => $j->on('st.id', '=', 'le.student_id')->where('st.school_id', '=', $s))
                ->leftJoin('student_enrollments as se', fn ($j) => $j->on('se.id', '=', 'le.student_enrollment_id')->where('se.school_id', '=', $s))
                ->leftJoin('academic_years as ay', fn ($j) => $j->on('ay.id', '=', 'se.academic_year_id')->where('ay.school_id', '=', $s))
                ->leftJoin('campuses as c', fn ($j) => $j->on('c.id', '=', 'se.campus_id')->where('c.school_id', '=', $s))
                ->leftJoin('grade_levels as g', fn ($j) => $j->on('g.id', '=', 'se.grade_level_id')->where('g.school_id', '=', $s))
                ->leftJoin('sections as sec', fn ($j) => $j->on('sec.id', '=', 'se.section_id')->where('sec.school_id', '=', $s))
                ->where('le.school_id', $s)
                ->whereIn('le.action', $this->actions($viewer))
                ->select([
                    'le.id', 'le.action', 'le.reason_code', 'le.occurred_at', 'le.student_id',
                    'st.first_name', 'st.middle_name', 'st.last_name', 'st.student_number',
                    'se.campus_id', 'se.starts_on', 'se.ends_on',
                    'ay.name as academic_year', 'c.name as campus', 'g.name as grade_level', 'sec.name as section',
                ])
                ->selectRaw('(le.occurred_at AT TIME ZONE ?)::date AS occurred_on', [$timezone]),
            'r',
        );

        if (($filters['action'] ?? '') !== '') {
            $query->where('r.action', $filters['action']);
        }
        if (($filters['reason'] ?? '') !== '') {
            $query->where('r.reason_code', $filters['reason']);
        }
        if (($filters['campus_id'] ?? '') !== '') {
            // A Student status change has no placement, so a Campus filter keeps placement events only.
            $query->where('r.campus_id', $filters['campus_id']);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('r.occurred_on', '>=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('r.occurred_on', '<=', $filters['to']);
        }
        if (trim($filters['q'] ?? '') !== '') {
            StudentSearch::anyField($query, $filters['q'], 'r');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(object $row, bool $placementColumn): array
    {
        $beforeStart = $row->action === StudentLifecycleReason::CANCEL_ENROLLMENT && $row->starts_on !== null && $row->ends_on !== null
            && (new \DateTimeImmutable((string) $row->ends_on))->modify('+1 day')->format('Y-m-d') === substr((string) $row->starts_on, 0, 10);

        $values = [
            'key' => (string) $row->id,
            'occurred_on' => substr((string) $row->occurred_on, 0, 10),
            'student' => [
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name], fn ($p) => $p !== null && $p !== ''))),
                'studentNumber' => (string) $row->student_number,
                // The report's own authority (whole-School students.view) opens the Profile.
                'url' => '/app/students/'.$row->student_id,
            ],
            'action' => $beforeStart ? self::CANCELLED_BEFORE_START : self::ACTION_LABELS[$row->action],
            'reason' => StudentLifecycleReason::LABELS[$row->reason_code] ?? 'Recorded reason',
        ];
        if ($placementColumn) {
            $values['placement'] = in_array($row->action, self::PLACEMENT_ACTIONS, true) ? [
                'academicYear' => $row->academic_year,
                'campus' => $row->campus,
                'gradeLevel' => $row->grade_level,
                'section' => $row->section,
            ] : null;
        }

        return $values;
    }
}
