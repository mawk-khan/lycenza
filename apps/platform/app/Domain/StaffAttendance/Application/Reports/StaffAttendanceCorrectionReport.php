<?php

namespace App\Domain\StaffAttendance\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * HR.14 (D8-15, D20-33): the Staff Attendance modification log -- every
 * append-only correction (`staff_attendance_corrections`) of records dated in
 * a range: the recorded halves before and after, the closed reason, when it
 * was corrected and (owner decision 2026-11-05) the corrector's DISPLAY NAME
 * only -- never a User id, email or audit metadata. The source record is
 * never rewritten; this reads the evidence. Whole-School
 * `hr.staff_attendance.view`.
 */
final class StaffAttendanceCorrectionReport implements HrReport
{
    public const string KEY = 'hr.attendance_corrections';

    public const int MAX_RANGE_DAYS = 366;

    public const array REASON_LABELS = [
        'entered_in_error' => 'Entered in error',
        'late_information' => 'Information received later',
        'administrative_review' => 'Administrative review',
        'other' => 'Other',
    ];

    private const array HALF = ['present' => 'Present', 'absent' => 'Absent'];

    public function __construct(private readonly TenantContext $context, private readonly HrReportLabels $labels) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Staff attendance corrections',
            description: 'Every correction of staff attendance dated in the range: the halves before and after, the reason, when and by whom.',
            category: 'attendance',
            owner: 'Staff Attendance',
            authority: ['hr.staff_attendance.view'],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D8-15', 'D20-33'],
            filters: ['from', 'to', 'reason', 'corrected_by', 'q'],
            columns: [
                new C('date', 'Attendance date', 'date'),
                new C('employee', 'Staff member', 'employee'),
                new C('before', 'Before'),
                new C('after', 'After'),
                new C('reason', 'Reason'),
                new C('corrected_at', 'Corrected', 'datetime'),
                new C('corrected_by', 'Corrected by'),
            ],
            sorts: ['corrected_desc' => 'Most recently corrected', 'date_desc' => 'Attendance date, newest first'],
            perPage: 25,
            maxRangeDays: self::MAX_RANGE_DAYS,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return [
            'reason' => array_map(fn (string $k) => ['value' => $k, 'label' => self::REASON_LABELS[$k]], StaffAttendanceCorrection::REASONS),
            'corrected_by' => array_map(fn (array $c) => ['value' => $c['token'], 'label' => $c['name']], $this->correctors($school)),
        ];
    }

    /**
     * This School's correctors, each with an opaque filter token (a keyed
     * digest of the School and User), so no User id ever reaches the browser.
     *
     * @return list<array{token: string, userId: string, name: string}>
     */
    private function correctors(School $school): array
    {
        $ids = $this->context->withSchool($school, fn () => DB::table('staff_attendance_corrections')->where('school_id', $school->id)
            ->distinct()->limit(200)->pluck('corrected_by_user_id')->all());

        return User::query()->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $u) => [
                'token' => substr(hash_hmac('sha256', $school->id.'|'.$u->id, (string) config('app.key')), 0, 20),
                'userId' => (string) $u->id,
                'name' => (string) $u->name,
            ])->values()->all();
    }

    public function defaults(School $school, array $options): array
    {
        $today = SchoolTimezone::today($school);

        return ['from' => CarbonImmutable::parse($today)->startOfMonth()->toDateString(), 'to' => $today];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $corrector = ($filters['corrected_by'] ?? '') === '' ? null
            : (collect($this->correctors($school))->firstWhere('token', $filters['corrected_by'])['userId'] ?? null);

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $corrector) {
            $query = DB::table('staff_attendance_corrections as c')
                ->join('staff_attendance_records as r', fn ($j) => $j->on('r.id', '=', 'c.staff_attendance_record_id')->on('r.school_id', '=', 'c.school_id'))
                ->where('c.school_id', $school->id)->whereBetween('r.attendance_date', [$filters['from'], $filters['to']])
                ->when(($filters['reason'] ?? '') !== '', fn ($q) => $q->where('c.reason_code', $filters['reason']))
                ->when($corrector !== null, fn ($q) => $q->where('c.corrected_by_user_id', $corrector));
            HrReportLabels::searchEmployment($query, 'c.employment_record_id', $filters['q'] ?? '');
            $sort === 'date_desc' ? $query->orderByDesc('r.attendance_date')->orderByDesc('c.created_at') : $query->orderByDesc('c.created_at');
            $paginator = $query->orderBy('c.id')->paginate(25, ['c.*', 'r.attendance_date'], 'page', $page);

            $rows = $paginator->getCollection();
            $labels = $this->labels->employments($school, $rows->pluck('employment_record_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
            $names = User::query()->whereIn('id', $rows->pluck('corrected_by_user_id')->unique()->values())->pluck('name', 'id');
            $halves = fn (?string $a, ?string $b) => (self::HALF[$a] ?? '—').' / '.(self::HALF[$b] ?? '—');

            return HrReportRows::map($paginator, fn ($c) => [
                'key' => $c->id,
                'date' => (string) $c->attendance_date,
                'employee' => $labels[strtolower((string) $c->employment_record_id)]['employee'] ?? null,
                'before' => $halves($c->before_first_half_status, $c->before_second_half_status),
                'after' => $halves($c->after_first_half_status, $c->after_second_half_status),
                'reason' => self::REASON_LABELS[$c->reason_code] ?? $c->reason_code,
                'corrected_at' => CarbonImmutable::parse($c->created_at, 'UTC')->toIso8601String(),
                'corrected_by' => $names[$c->corrected_by_user_id] ?? null,
            ]);
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => StaffAttendanceCorrection::query()->where('school_id', $school->id)->exists());
    }
}
