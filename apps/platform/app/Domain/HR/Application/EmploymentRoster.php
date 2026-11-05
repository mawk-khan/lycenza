<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * HRX.3 (ADR 0065 §24.4): HR's directory-tier answer to "which employments
 * of this School span this date?" -- for an administrative daily register
 * (Staff Attendance). Labels only: EmploymentRecord id, Employee id, number
 * and name, the employment status, and whether it is in force today
 * (`active`/`notice_period` of an active Employee). Never work contact, HR
 * profile, compensation or account data.
 *
 * It identifies; it authorizes nothing. HR depends on no consumer of this
 * answer.
 */
class EmploymentRoster
{
    private const LIMIT = 2000;

    /** HR.16C: the largest page paged() serves (a response bound, never a population cap). */
    public const MAX_PAGE = 200;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * HR.16C: ONE PAGE (server-side, offset) of the employments of the School
     * that $constrain selects -- ordered by the Employee's name, number and
     * the employment id (stable), with the total of the WHOLE population. No
     * cap: a page is bounded (1..MAX_PAGE), the population never is. The
     * caller adds its own predicate (a register date, a month) and may union
     * its own facts in, e.g. employments holding a record on the date.
     *
     * @param  callable(Builder<EmploymentRecord>): mixed  $constrain
     * @return array{rows: list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>, page: int, perPage: int, total: int, lastPage: int}
     */
    public function paged(School $school, callable $constrain, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, self::MAX_PAGE));
        $page = max(1, $page);

        return $this->context->withSchool($school, function () use ($school, $constrain, $page, $perPage): array {
            $query = EmploymentRecord::query()->where('employment_records.school_id', $school->id);
            $constrain($query);
            $total = (clone $query)->count();
            $rows = $query->join('employees as pe', fn ($j) => $j->on('pe.id', '=', 'employment_records.employee_id')->on('pe.school_id', '=', 'employment_records.school_id'))
                ->orderBy('pe.full_name')->orderBy('pe.employee_number')->orderBy('employment_records.id')
                ->select('employment_records.*')->offset(($page - 1) * $perPage)->limit($perPage)
                ->with('employee:id,employee_number,full_name,record_status')->get()
                ->map(fn (EmploymentRecord $r) => self::row($r))->values()->all();

            return ['rows' => $rows, 'page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / $perPage))];
        });
    }

    /**
     * HR.16C: the predicate "employments (any status but `draft`) whose dates
     * contain $date", for paged() / pageOverlapping-style callers.
     *
     * @param  Builder<EmploymentRecord>  $query
     */
    public static function covering(Builder $query, string $date): void
    {
        $query->where('employment_records.status', '<>', 'draft')->where('employment_records.starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('employment_records.ends_on')->orWhere('employment_records.ends_on', '>=', $date));
    }

    /**
     * HR.16C: the predicate "employments (any status but `draft`) whose dates
     * overlap [from, to]" -- a monthly register's population.
     *
     * @param  Builder<EmploymentRecord>  $query
     */
    public static function overlappingRange(Builder $query, string $from, string $to): void
    {
        $query->where('employment_records.status', '<>', 'draft')->where('employment_records.starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('employment_records.ends_on')->orWhere('employment_records.ends_on', '>=', $from));
    }

    /**
     * ORG.9C-1: several EmploymentRecords' dates in one query (inclusive; `endsOn` null = open-ended).
     *
     * @param  list<string>  $employmentRecordIds
     * @return array<string, array{startsOn: string, endsOn: ?string}>
     */
    public function spans(School $school, array $employmentRecordIds): array
    {
        if ($employmentRecordIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => EmploymentRecord::query()->where('school_id', $school->id)->whereIn('id', $employmentRecordIds)
            ->get(['id', 'starts_on', 'ends_on'])
            ->mapWithKeys(fn (EmploymentRecord $r) => [$r->id => ['startsOn' => $r->starts_on->toDateString(), 'endsOn' => $r->ends_on?->toDateString()]])->all());
    }

    /**
     * HR.10 (ADR 0065 §29.6): ONE PAGE of a COMPLETE traversal -- the
     * employments (any status but `draft`) whose dates overlap [from, to],
     * ordered by id, strictly after $afterId. There is no population cap: a caller pages until a page is shorter than
     * $limit, so no employment is ever silently omitted. Labels only.
     *
     * @param  string  $from  School-local Y-m-d
     * @param  string  $to  School-local Y-m-d
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>
     */
    public function pageOverlapping(School $school, string $from, string $to, ?string $afterId, int $limit): array
    {
        $limit = max(1, min($limit, self::LIMIT));

        return $this->context->withSchool($school, fn (): array => EmploymentRecord::query()->where('school_id', $school->id)
            ->where('status', '<>', 'draft')->where('starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')->limit($limit)
            ->with('employee:id,employee_number,full_name,record_status')->get()
            ->map(fn (EmploymentRecord $r) => self::row($r))->values()->all());
    }

    /**
     * The same labels for these EmploymentRecord ids of the School (another School's ids yield nothing).
     *
     * @param  list<string>  $employmentRecordIds
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>
     */
    public function labels(School $school, array $employmentRecordIds): array
    {
        if ($employmentRecordIds === []) {
            return [];
        }

        return $this->rows($school, fn ($q) => $q->whereIn('id', $employmentRecordIds));
    }

    /**
     * HRX.4: one EmploymentRecord's dates (inclusive; `endsOn` null = open-ended), or null for another School's / an unknown id.
     *
     * @return array{startsOn: string, endsOn: ?string}|null
     */
    public function span(School $school, string $employmentRecordId): ?array
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId): ?array {
            $record = EmploymentRecord::query()->where('school_id', $school->id)->find($employmentRecordId);

            return $record === null ? null : ['startsOn' => $record->starts_on->toDateString(), 'endsOn' => $record->ends_on?->toDateString()];
        });
    }

    /**
     * @param  callable(Builder<EmploymentRecord>): mixed  $filter
     * @return list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>
     */
    private function rows(School $school, callable $filter): array
    {
        return $this->context->withSchool($school, function () use ($school, $filter): array {
            $query = EmploymentRecord::query()->where('school_id', $school->id);
            $filter($query);

            return $query->with('employee:id,employee_number,full_name,record_status')->limit(self::LIMIT)->get()
                ->map(fn (EmploymentRecord $r) => self::row($r))
                ->sortBy(fn (array $row) => [$row['fullName'] ?? '', $row['employmentRecordId']])->values()->all();
        });
    }

    /** @return array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool} */
    private static function row(EmploymentRecord $r): array
    {
        return [
            'employmentRecordId' => $r->id,
            'employeeId' => $r->employee_id,
            'employeeNumber' => $r->employee?->employee_number,
            'fullName' => $r->employee?->full_name,
            'status' => $r->status,
            'current' => in_array($r->status, EmploymentCoverage::CURRENT_STATUSES, true) && $r->employee?->isActive() === true,
            // HR.9: whether the Employee itself is active (a planned employment of an inactive Employee is not assignable).
            'employeeActive' => $r->employee?->isActive() === true,
        ];
    }
}
