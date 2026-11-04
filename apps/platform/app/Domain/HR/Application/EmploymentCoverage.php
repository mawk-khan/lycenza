<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TCH.2 (ADR 0063 sections 15, 20): HR's answer to "is this Employee an
 * active record with a planned or current employment covering this date?"
 * -- for ADMINISTRATIVE planning (assigning another Employee), never for
 * identifying an actor (that is ActingEmployeeResolver, which is stricter:
 * `active`/`notice_period` only, on the day of use).
 *
 * `pre_joining` counts here so a future hire can be planned in advance;
 * `draft` and the terminal statuses do not. It never decides use-time
 * authority: a planned assignment still grants nothing until
 * ActingEmployee eligibility holds on the day.
 *
 * hold() runs inside the caller's transaction and reads the Employee and
 * the covering EmploymentRecord FOR SHARE, so an archive or an
 * EmploymentService::end() either commits first (and is seen) or waits for
 * the caller to commit. HR depends on no consumer of this answer.
 */
class EmploymentCoverage
{
    public const string COVERED = 'covered';

    /** No Employee with this id in this School (another School's id included). */
    public const string EMPLOYEE_NOT_FOUND = 'employee_not_found';

    public const string EMPLOYEE_UNAVAILABLE = 'employee_unavailable';

    public const string NOT_EMPLOYED = 'not_employed';

    /** HRX.1: no EmploymentRecord with this id in this School (another School's id included). */
    public const string RECORD_NOT_FOUND = 'record_not_found';

    /** Statuses that make an EmploymentRecord a planned or current engagement. */
    public const array PLANNED_OR_CURRENT_STATUSES = ['pre_joining', 'active', 'notice_period'];

    /** HRX.3: an engagement that is in force today (ActingEmployee's eligible statuses). */
    public const array CURRENT_STATUSES = ['active', 'notice_period'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * HR.15A: the same answer as hold(), as a constraint on an Employee query
     * -- an active Employee with a planned or current employment covering
     * $date -- for READ-ONLY candidate lists (DutyCandidateReader). It never
     * replaces hold() at write time: a list can be stale, the locked re-read
     * cannot.
     *
     * @param  Builder<Employee>  $employees
     * @param  string  $date  School-local Y-m-d
     * @return Builder<Employee>
     */
    public static function constrainToCoveredOn(Builder $employees, string $date): Builder
    {
        return $employees->where('employees.record_status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('employment_records as cov')
                ->whereColumn('cov.employee_id', 'employees.id')
                ->whereColumn('cov.school_id', 'employees.school_id')
                ->where('cov.starts_on', '<=', $date)
                ->where(fn ($e) => $e->whereNull('cov.ends_on')->orWhere('cov.ends_on', '>=', $date))
                ->whereIn('cov.status', self::PLANNED_OR_CURRENT_STATUSES));
    }

    /**
     * @param  string  $date  School-local Y-m-d
     * @return self::COVERED|self::EMPLOYEE_NOT_FOUND|self::EMPLOYEE_UNAVAILABLE|self::NOT_EMPLOYED
     */
    public function hold(School $school, string $employeeId, string $date): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::hold() must run inside a database transaction.');
        }

        return $this->context->withSchool($school, function () use ($school, $employeeId, $date): string {
            $employee = Employee::query()->where('school_id', $school->id)->whereKey($employeeId)->sharedLock()->first();

            if ($employee === null) {
                return self::EMPLOYEE_NOT_FOUND;
            }

            if (! $employee->isActive()) {
                return self::EMPLOYEE_UNAVAILABLE;
            }

            $covered = EmploymentRecord::query()
                ->where('school_id', $school->id)
                ->where('employee_id', $employee->id)
                ->where('starts_on', '<=', $date)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
                ->whereIn('status', self::PLANNED_OR_CURRENT_STATUSES)
                ->sharedLock()
                ->first();

            return $covered !== null ? self::COVERED : self::NOT_EMPLOYED;
        });
    }

    /**
     * S7 (ADR 0063 §47): the last day (inclusive) of the planned or current
     * employment covering $date -- null when it is open-ended or when nothing
     * covers $date. Call it after hold() answered COVERED, in the same
     * transaction: hold() already holds that record FOR SHARE, so an
     * EmploymentService::end() cannot move it in between. A grant that runs
     * past this day would outlive the employment that justifies it.
     *
     * @param  string  $date  School-local Y-m-d
     */
    public function coveringEndsOn(School $school, string $employeeId, string $date): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::coveringEndsOn() must run inside a database transaction.');
        }

        return $this->context->withSchool($school, function () use ($school, $employeeId, $date): ?string {
            $endsOn = EmploymentRecord::query()
                ->where('school_id', $school->id)
                ->where('employee_id', $employeeId)
                ->where('starts_on', '<=', $date)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
                ->whereIn('status', self::PLANNED_OR_CURRENT_STATUSES)
                ->sharedLock()
                ->first()
                ?->ends_on;

            return $endsOn?->toDateString();
        });
    }

    /**
     * HRX.1 (ADR 0065 §3): the same administrative answer for ONE
     * EmploymentRecord -- "does this employment, of an active Employee, plan
     * or hold an engagement overlapping [from, to]?" -- for Leave's policy
     * assignments and allocations, which attach to the EmploymentRecord. It
     * reads the Employee and the record FOR SHARE in the caller's
     * transaction, like hold(). HR depends on no consumer of this answer.
     *
     * @param  string  $from  School-local Y-m-d, inclusive
     * @param  string|null  $to  School-local Y-m-d, inclusive; null = open-ended
     * @return self::COVERED|self::RECORD_NOT_FOUND|self::EMPLOYEE_UNAVAILABLE|self::NOT_EMPLOYED
     */
    public function holdRecord(School $school, string $employmentRecordId, string $from, ?string $to): string
    {
        return $this->holdRecordFor($school, $employmentRecordId, $from, $to, whole: false);
    }

    /**
     * HRX.2 (ADR 0065 §23): holdRecord(), but the planned or current
     * engagement must cover the WHOLE of [from, to] -- a leave request is
     * only for days the employment actually spans.
     *
     * @return self::COVERED|self::RECORD_NOT_FOUND|self::EMPLOYEE_UNAVAILABLE|self::NOT_EMPLOYED
     */
    public function holdRecordCovering(School $school, string $employmentRecordId, string $from, string $to): string
    {
        return $this->holdRecordFor($school, $employmentRecordId, $from, $to, whole: true);
    }

    /**
     * HRX.3 (ADR 0065 §24.7): may Staff Attendance hold evidence for this
     * EmploymentRecord on this date? The record's dates must contain the
     * date. With `$currentOnly` (initial recording, ADR 0065 §7) the
     * employment must also be `active` or `notice_period`, of an active
     * Employee. Without it (a correction of existing evidence) any status
     * qualifies -- correcting history after separation is legitimate.
     *
     * Reads the Employee and the record FOR SHARE in the caller's
     * transaction, like holdRecord(). HR depends on no consumer of this
     * answer.
     *
     * @param  string  $date  School-local Y-m-d
     * @return self::COVERED|self::RECORD_NOT_FOUND|self::EMPLOYEE_UNAVAILABLE|self::NOT_EMPLOYED
     */
    public function holdRecordAttendable(School $school, string $employmentRecordId, string $date, bool $currentOnly = true): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::holdRecordAttendable() must run inside a database transaction.');
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $date, $currentOnly): string {
            $record = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentRecordId)->first();
            if ($record === null) {
                return self::RECORD_NOT_FOUND;
            }

            $employee = Employee::query()->where('school_id', $school->id)->whereKey($record->employee_id)->sharedLock()->first();
            if ($employee === null || ($currentOnly && ! $employee->isActive())) {
                return self::EMPLOYEE_UNAVAILABLE;
            }

            $covered = EmploymentRecord::query()
                ->where('school_id', $school->id)
                ->whereKey($record->id)
                ->where('starts_on', '<=', $date)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
                ->when($currentOnly, fn ($q) => $q->whereIn('status', self::CURRENT_STATUSES))
                ->sharedLock()
                ->first();

            return $covered !== null ? self::COVERED : self::NOT_EMPLOYED;
        });
    }

    /**
     * HR.10 (ADR 0065 §29.6): for a batch writer (Leave's monthly accrual),
     * which of these EmploymentRecords span the WHOLE of [from, to] by their
     * dates, in any status but `draft` -- the dated facts decide (HR.1A): an
     * employment that covered a past month is eligible for it even if it has
     * ended since. The rows are read FOR SHARE in ONE statement, in id order,
     * inside the caller's transaction, so a concurrent end, start change or
     * status change commits wholly before (and is seen) or waits.
     *
     * @param  list<string>  $employmentRecordIds
     * @return list<string> the ids that span the whole range
     */
    public function holdRecordsSpanning(School $school, array $employmentRecordIds, string $from, string $to): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::holdRecordsSpanning() must run inside a database transaction.');
        }
        if ($employmentRecordIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $employmentRecordIds)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'status', 'starts_on', 'ends_on'])
            ->filter(fn (EmploymentRecord $r) => $r->status !== 'draft' && $r->starts_on->toDateString() <= $from
                && ($r->ends_on === null || $r->ends_on->toDateString() >= $to))
            ->map(fn (EmploymentRecord $r) => (string) $r->id)
            ->values()
            ->all());
    }

    private function holdRecordFor(School $school, string $employmentRecordId, string $from, ?string $to, bool $whole): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::holdRecord() must run inside a database transaction.');
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $from, $to, $whole): string {
            $record = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentRecordId)->first();
            if ($record === null) {
                return self::RECORD_NOT_FOUND;
            }

            $employee = Employee::query()->where('school_id', $school->id)->whereKey($record->employee_id)->sharedLock()->first();
            if ($employee === null || ! $employee->isActive()) {
                return self::EMPLOYEE_UNAVAILABLE;
            }

            $covered = EmploymentRecord::query()
                ->where('school_id', $school->id)
                ->whereKey($record->id)
                ->whereIn('status', self::PLANNED_OR_CURRENT_STATUSES)
                ->when($whole, fn ($q) => $q->where('starts_on', '<=', $from)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $to)))
                ->when(! $whole && $to !== null, fn ($q) => $q->where('starts_on', '<=', $to))
                ->when(! $whole, fn ($q) => $q->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from)))
                ->sharedLock()
                ->first();

            return $covered !== null ? self::COVERED : self::NOT_EMPLOYED;
        });
    }
}
