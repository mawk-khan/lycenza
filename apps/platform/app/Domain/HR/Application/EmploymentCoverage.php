<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
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

    public function __construct(private readonly TenantContext $context) {}

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
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EmploymentCoverage::holdRecord() must run inside a database transaction.');
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $from, $to): string {
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
                ->when($to !== null, fn ($q) => $q->where('starts_on', '<=', $to))
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
                ->sharedLock()
                ->first();

            return $covered !== null ? self::COVERED : self::NOT_EMPLOYED;
        });
    }
}
