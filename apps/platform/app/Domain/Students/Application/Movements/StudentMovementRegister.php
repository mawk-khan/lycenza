<?php

namespace App\Domain\Students\Application\Movements;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIS.EXP-2: the School-wide Student movement and exit register -- a
 * read-only PROJECTION of the canonical history, never a source of truth
 * and never a second movement engine (no table, no write, no audit-log
 * reconstruction).
 *
 * Sources (all School-owned, forced RLS, plus an explicit `school_id`):
 * - `student_enrollments` -- every placement with its frozen year, Campus,
 *   Grade and Section, status and dates;
 * - `enrollment_rollover_items` -- executed promote/repeat decisions linking a
 *   source placement to the placement the rollover created;
 * - `student_lifecycle_events` -- the closed reasons of withdrawals and the
 *   Student deactivations/reactivations (StudentMovementType documents each
 *   type's source and effective date).
 *
 * One canonical fact = one row, keyed deterministically (presentation only):
 * - `transfer:{source placement}` -- a transfer ENDS the source (`transferred`,
 *   `ends_on` = effective - 1) and INSERTS the destination starting the next
 *   day in the same year (StudentEnrollmentService::transferPlacement): the
 *   pair is one row, and the destination is never also an entry;
 * - `rollover:{item}` -- the target placement is never also an entry, and a
 *   source completed at year end is folded into this row (never a separate
 *   PLACEMENT_COMPLETED);
 * - `entry:` / `withdrawal:` / `completion:{placement}`, `lifecycle:{event}`.
 * Cancelled placements are corrections and never listed as entries or
 * exits, nor as either end of a rollover; a transfer whose destination was
 * later cancelled still shows its (cancelled) destination, so its type
 * stays right. A Student status change (no academic-year link) falls in a
 * year filter by its date. SIS.HARDEN-1 (R-4): one dated between two years
 * belongs to NO year -- it is never moved into a neighbouring one; it is
 * marked `outsideAcademicYear` and found by the OUTSIDE_ACADEMIC_YEAR
 * filter (and, as always, unfiltered or by date). A
 * rollover row belongs to its TARGET year. A withdrawal's
 * own lifecycle event only supplies its reason (never a second row).
 *
 * History, not today's state: from/to are the historical placements' own
 * frozen rows. Labels are the CURRENT names of those rows (a renamed Section
 * shows its new name; no historical label is stored). Student names are
 * current. No actor (the audit log holds who acted), no free text, no
 * Guardian, date of birth or Finance data.
 *
 * Authorization-neutral: the caller authorizes (whole-School
 * `enrollments.view`, a declared whole-School operation) and decides whether
 * Student lifecycle rows are included (whole-School `students.view`).
 * Bounded: one count and one page query, then one eager-loaded placement
 * read for the page -- constant whatever the history size.
 */
final class StudentMovementRegister
{
    public const int PER_PAGE = 25;

    /** SIS.HARDEN-1 (R-4): the academic-year filter value for events dated in no year of the School. */
    public const string OUTSIDE_ACADEMIC_YEAR = 'outside';

    /** sort key => ORDER BY (a closed map: no request input reaches SQL). */
    public const array SORTS = [
        'date_desc' => 'r.effective_date desc, r.event_key desc',
        'date_asc' => 'r.effective_date asc, r.event_key asc',
        'student' => 'st.last_name asc nulls first, st.first_name asc, st.id asc, r.effective_date desc, r.event_key desc',
        'type' => 'r.type asc, r.effective_date desc, r.event_key desc',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{academic_year_id?: ?string, from?: ?string, to?: ?string, category?: ?string, type?: ?string, campus_id?: ?string, grade_level_id?: ?string, section_id?: ?string, q?: ?string, sort?: ?string}  $filters  validated by the caller
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(School $school, array $filters, bool $includeStudentLifecycle, int $page = 1): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $filters, $includeStudentLifecycle, $page): LengthAwarePaginator {
            $query = $this->query($school, $filters, $includeStudentLifecycle);
            $order = self::SORTS[$filters['sort'] ?? 'date_desc'] ?? self::SORTS['date_desc'];

            /** @var LengthAwarePaginator<int, object> $raw */
            $raw = $query->orderByRaw($order)->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
            $placements = $this->placements($raw->getCollection());
            $years = $this->yearsFor($school, $raw->getCollection());

            return $raw->through(fn (object $row): array => $this->present($row, $placements, $years));
        });
    }

    /** The projection with its filters (no ORDER BY, no page). */
    private function query(School $school, array $filters, bool $includeStudentLifecycle): Builder
    {
        [$union, $bindings] = $this->union($school, $includeStudentLifecycle);

        $query = DB::query()
            ->fromRaw("({$union}) as r", $bindings)
            ->join('students as st', fn ($j) => $j->on('st.id', '=', 'r.student_id')->where('st.school_id', '=', $school->id))
            ->select([
                'r.event_key', 'r.type', 'r.effective_date', 'r.academic_year_id', 'r.student_id',
                'r.from_enrollment_id', 'r.to_enrollment_id', 'r.reason_code',
                'st.first_name', 'st.middle_name', 'st.last_name', 'st.student_number', 'st.status as student_status',
            ]);

        if (! empty($filters['type'])) {
            $query->where('r.type', $filters['type']);
        } elseif (! empty($filters['category'])) {
            $query->whereIn('r.type', StudentMovementType::valuesIn($filters['category']));
        }
        if (! empty($filters['from'])) {
            $query->where('r.effective_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('r.effective_date', '<=', $filters['to']);
        }
        if (($filters['academic_year_id'] ?? null) === self::OUTSIDE_ACADEMIC_YEAR) {
            // SIS.HARDEN-1 (R-4): no canonical year link, and no year of this
            // School contains the date (the years are few: a bounded anti-join).
            $query->whereNull('r.academic_year_id')->whereNotExists(fn (Builder $y) => $y->selectRaw('1')->from('academic_years as ay')
                ->where('ay.school_id', $school->id)->whereRaw('r.effective_date BETWEEN ay.starts_on AND ay.ends_on'));
        } elseif (! empty($filters['academic_year_id'])) {
            // The canonical year link where one exists; a Student lifecycle
            // event has none, so it falls in the year whose dates contain it.
            $year = AcademicYear::query()->where('school_id', $school->id)->find($filters['academic_year_id']);
            $query->where(fn (Builder $q) => $year === null
                ? $q->whereRaw('1 = 0')
                : $q->where('r.academic_year_id', $year->id)->orWhere(fn (Builder $l) => $l->whereNull('r.academic_year_id')
                    ->whereBetween('r.effective_date', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])));
        }
        foreach (['campus_id', 'grade_level_id', 'section_id'] as $column) {
            if (! empty($filters[$column])) {
                // Either end of the movement (a Student lifecycle row has no placement: never matches).
                $query->where(fn (Builder $q) => $q->where("r.from_{$column}", $filters[$column])->orWhere("r.to_{$column}", $filters[$column]));
            }
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            StudentSearch::anyField($query, $filters['q'], 'st');
        }

        return $query;
    }

    /**
     * One pass over this School's history: its placements are read ONCE
     * into a CTE (forced RLS is applied at that scan), then transfers are
     * paired and entries ranked with joins and window functions -- never a
     * correlated subquery per row, which RLS's security barrier would turn
     * into repeated scans.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function union(School $school, bool $includeStudentLifecycle): array
    {
        $s = $school->id;
        $executed = "i.target_enrollment_id IS NOT NULL AND i.decision IN ('promote', 'repeat')";

        $ctes = "WITH pl AS MATERIALIZED (
                SELECT id, student_id, academic_year_id, campus_id, grade_level_id, section_id, starts_on, ends_on, status, created_at
                FROM student_enrollments WHERE school_id = ?
            ),
            -- THE destination of each transfer: the same Student's placement in the same year starting the
            -- day after the source ended -- a live one first, else a later-cancelled one (so the type stays
            -- right), earliest created. One rule for the transfer row AND the entry exclusion.
            dest AS (
                SELECT DISTINCT ON (src.id) src.id AS src_id, d.id AS dst_id, d.campus_id AS dst_campus_id, d.grade_level_id AS dst_grade_level_id, d.section_id AS dst_section_id
                FROM pl src
                JOIN pl d ON d.student_id = src.student_id AND d.academic_year_id = src.academic_year_id
                         AND d.starts_on = src.ends_on + 1 AND d.id <> src.id
                WHERE src.status = 'transferred'
                ORDER BY src.id, (d.status = 'cancelled'), d.created_at, d.id
            ),
            -- Executed rollovers whose source and target are both live placements.
            ro AS (
                SELECT i.id, i.decision, i.student_id, i.source_enrollment_id, i.target_enrollment_id, t.starts_on, t.academic_year_id,
                       so.campus_id AS from_campus_id, so.grade_level_id AS from_grade_level_id, so.section_id AS from_section_id,
                       t.campus_id AS to_campus_id, t.grade_level_id AS to_grade_level_id, t.section_id AS to_section_id
                FROM enrollment_rollover_items i
                JOIN pl t ON t.id = i.target_enrollment_id AND t.status <> 'cancelled'
                JOIN pl so ON so.id = i.source_enrollment_id AND so.status <> 'cancelled'
                WHERE i.school_id = ? AND {$executed}
            ),
            -- Live placements in each Student's order (the first is the first enrollment).
            ranked AS (
                SELECT p.*, row_number() OVER (PARTITION BY p.student_id ORDER BY p.starts_on, p.created_at, p.id) AS rn
                FROM pl p WHERE p.status <> 'cancelled'
            ),
            withdrawn_reason AS (
                SELECT DISTINCT ON (le.student_enrollment_id) le.student_enrollment_id, le.reason_code
                FROM student_lifecycle_events le
                WHERE le.school_id = ? AND le.action = 'enrollment_withdrawn'
                ORDER BY le.student_enrollment_id, le.occurred_at DESC, le.id DESC
            )";
        $bindings = [$s, $s, $s];

        $parts = [
            // Transfers: the ended source and its destination (one row).
            "SELECT 'transfer:' || src.id::text AS event_key,
                    CASE WHEN dest.dst_campus_id IS NOT NULL AND dest.dst_campus_id <> src.campus_id THEN 'campus_transfer' ELSE 'section_change' END AS type,
                    (src.ends_on + 1) AS effective_date, src.academic_year_id, src.student_id,
                    src.id AS from_enrollment_id, dest.dst_id AS to_enrollment_id, NULL::varchar AS reason_code,
                    src.campus_id AS from_campus_id, src.grade_level_id AS from_grade_level_id, src.section_id AS from_section_id,
                    dest.dst_campus_id AS to_campus_id, dest.dst_grade_level_id AS to_grade_level_id, dest.dst_section_id AS to_section_id
             FROM pl src LEFT JOIN dest ON dest.src_id = src.id
             WHERE src.status = 'transferred'",
            // Rollovers: promote / repeat, source -> the placement the rollover created.
            "SELECT 'rollover:' || ro.id::text, CASE ro.decision WHEN 'promote' THEN 'promotion' ELSE 'retention' END,
                    ro.starts_on, ro.academic_year_id, ro.student_id, ro.source_enrollment_id, ro.target_enrollment_id, NULL::varchar,
                    ro.from_campus_id, ro.from_grade_level_id, ro.from_section_id, ro.to_campus_id, ro.to_grade_level_id, ro.to_section_id
             FROM ro",
            // Entries: a live placement no transfer and no rollover created.
            "SELECT 'entry:' || p.id::text, CASE WHEN p.rn > 1 THEN 're_enrollment' ELSE 'initial_enrollment' END,
                    p.starts_on, p.academic_year_id, p.student_id, NULL::uuid, p.id, NULL::varchar,
                    NULL::uuid, NULL::uuid, NULL::uuid, p.campus_id, p.grade_level_id, p.section_id
             FROM ranked p
             LEFT JOIN dest ON dest.dst_id = p.id
             LEFT JOIN ro ON ro.target_enrollment_id = p.id
             WHERE dest.dst_id IS NULL AND ro.id IS NULL",
            // Withdrawals, with the reason of their own lifecycle event.
            "SELECT 'withdrawal:' || p.id::text, 'withdrawal', p.ends_on, p.academic_year_id, p.student_id, p.id, NULL::uuid, wr.reason_code,
                    p.campus_id, p.grade_level_id, p.section_id, NULL::uuid, NULL::uuid, NULL::uuid
             FROM pl p LEFT JOIN withdrawn_reason wr ON wr.student_enrollment_id = p.id
             WHERE p.status = 'withdrawn'",
            // Completions no executed rollover continued (a continued one is the rollover row).
            "SELECT 'completion:' || p.id::text, 'placement_completed', p.ends_on, p.academic_year_id, p.student_id, p.id, NULL::uuid, NULL::varchar,
                    p.campus_id, p.grade_level_id, p.section_id, NULL::uuid, NULL::uuid, NULL::uuid
             FROM pl p LEFT JOIN ro ON ro.source_enrollment_id = p.id
             WHERE p.status = 'completed' AND ro.id IS NULL",
        ];
        if ($includeStudentLifecycle) {
            $parts[] = "SELECT 'lifecycle:' || le.id::text, le.action, (le.occurred_at AT TIME ZONE ?)::date, NULL::uuid, le.student_id, NULL::uuid, NULL::uuid, le.reason_code,
                    NULL::uuid, NULL::uuid, NULL::uuid, NULL::uuid, NULL::uuid, NULL::uuid
             FROM student_lifecycle_events le
             WHERE le.school_id = ? AND le.action IN ('student_deactivated', 'student_reactivated')";
            $bindings = [...$bindings, SchoolTimezone::resolve($school)->getName(), $s];
        }

        // The projection itself is materialized ONCE, so the outer join and
        // the page/count never re-run it per Student (whatever the planner's
        // row estimates). Each row carries its from/to Campus, Grade and
        // Section, so the placement filters never re-join placements.
        return [$ctes.", movements AS MATERIALIZED (\n".implode("\nUNION ALL\n", $parts)."\n)\nSELECT * FROM movements", $bindings];
    }

    /**
     * The page's historical placements, in one eager-loaded read.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<string, StudentEnrollment>
     */
    private function placements(Collection $rows): Collection
    {
        $ids = $rows->flatMap(fn (object $r) => [$r->from_enrollment_id, $r->to_enrollment_id])->filter()->unique()->values()->all();

        return $ids === [] ? new Collection : StudentEnrollment::query()
            ->with(['academicYear:id,name', 'campus:id,name', 'gradeLevel:id,name', 'section:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * The School's year date ranges, read once and only when the page holds
     * a row without a canonical year link (R-4).
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{0: string, 1: string}>
     */
    private function yearsFor(School $school, Collection $rows): array
    {
        if (! $rows->contains(fn (object $r) => $r->academic_year_id === null)) {
            return [];
        }

        return AcademicYear::query()->where('school_id', $school->id)->get(['starts_on', 'ends_on'])
            ->map(fn (AcademicYear $y) => [$y->starts_on->toDateString(), $y->ends_on->toDateString()])->values()->all();
    }

    /**
     * @param  Collection<string, StudentEnrollment>  $placements
     * @param  list<array{0: string, 1: string}>  $years
     * @return array<string, mixed>
     */
    private function present(object $row, Collection $placements, array $years): array
    {
        $date = substr((string) $row->effective_date, 0, 10);
        $type = StudentMovementType::from((string) $row->type);
        $placement = fn (?string $id): ?array => ($p = $id === null ? null : $placements->get($id)) === null ? null : [
            'academicYear' => $p->academicYear?->name,
            'campus' => $p->campus?->name,
            'gradeLevel' => $p->gradeLevel?->name,
            'section' => $p->section?->name,
            'status' => $p->status,
        ];

        return [
            'id' => (string) $row->event_key,
            'effectiveDate' => $date,
            // R-4: honest, never a neighbouring year.
            'outsideAcademicYear' => $row->academic_year_id === null
                && ! collect($years)->contains(fn (array $y) => $date >= $y[0] && $date <= $y[1]),
            'category' => $type->category(),
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'student' => [
                'id' => (string) $row->student_id,
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name], fn ($p) => $p !== null && $p !== ''))),
                'studentNumber' => (string) $row->student_number,
            ],
            'from' => $placement($row->from_enrollment_id),
            'to' => $placement($row->to_enrollment_id),
            // A closed code only (StudentLifecycleReason::CODES): never free text.
            'reasonCode' => $row->reason_code,
        ];
    }
}
