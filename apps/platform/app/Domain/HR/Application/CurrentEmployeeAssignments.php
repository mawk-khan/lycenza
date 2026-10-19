<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ORG.7E-0 (ADR 0076 §42, D-E0-1 to D-E0-7): THE answer to "which of an
 * Employee's assignments are OPERATIONALLY CURRENT, and on which Campuses
 * is the Employee operationally present?". One definition, on the School's
 * own calendar date (SchoolTimezone::today()), every component required:
 *
 *   an `active` Employee (record_status)
 *   + an Employment in EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES
 *     (`active`, `notice_period`) whose dates cover the date
 *   + an assignment whose dates cover the date.
 *
 * The Employee's operational Campus set is the UNION of the non-NULL,
 * `active` Campuses of those current assignments: none, one or several.
 * Primary status plays no part. A NULL-Campus assignment is current but
 * places the Employee on no Campus.
 *
 * - Ended employment confers nothing, even where an assignment row is still
 *   date-open (a terminal status is excluded whatever its dates). History is
 *   never rewritten to make this true.
 * - Future-start and past-ended assignments confer nothing today.
 * - An inactive Campus contributes nothing; reactivating it restores the
 *   still-current assignment.
 * - Data, never authorization: it grants nothing. ORG.7E-1 builds the HR
 *   Campus access layer on it; HR capabilities are not Campus-adopted.
 * - Tenant-safe: explicit `school_id` predicates on every table, inside the
 *   target School's TenantContext. Employee identity stays School-wide
 *   (`employees` has no Campus).
 *
 * Semantically different readers stay separate and declared (payroll
 * period, staff-attendance date, leave coverage, reporting line, retention,
 * profile/directory display summaries): HrCurrentAssignmentArchitectureTest.
 */
final class CurrentEmployeeAssignments
{
    public function __construct(private readonly TenantContext $context) {}

    /** The School-local date "current" is judged on. */
    public function today(School $school): string
    {
        return SchoolTimezone::today($school);
    }

    /**
     * The School's operationally-current assignments on $date (default: the
     * School's today). MUST be run inside the target School's TenantContext.
     *
     * @return Builder<EmployeeAssignment>
     */
    public function current(School $school, ?string $date = null): Builder
    {
        /** @var Builder<EmployeeAssignment> $query */
        $query = EmployeeAssignment::query()->where('employee_assignments.school_id', $school->id);

        return $this->constrainToCurrent($query, $date ?? $this->today($school), 'employee_assignments');
    }

    /**
     * Constrains an `employee_assignments` query (table or alias $assignment)
     * to operationally-current rows on $date: the assignment covers the date,
     * and an EXISTS predicate requires its Employment (eligible status,
     * covering the date) and its active Employee, all in the same School.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $assignments
     * @return T
     */
    public function constrainToCurrent(Builder|QueryBuilder $assignments, string $date, string $assignment = 'ea'): Builder|QueryBuilder
    {
        return $assignments
            ->where("{$assignment}.starts_on", '<=', $date)
            ->where(fn ($w) => $w->whereNull("{$assignment}.ends_on")->orWhere("{$assignment}.ends_on", '>=', $date))
            ->whereExists(function (QueryBuilder $q) use ($assignment, $date): void {
                $q->selectRaw('1')
                    ->from('employment_records as cur_er')
                    ->join('employees as cur_e', fn ($j) => $j->on('cur_e.id', '=', 'cur_er.employee_id')->on('cur_e.school_id', '=', 'cur_er.school_id'))
                    ->whereColumn('cur_er.id', "{$assignment}.employment_record_id")
                    ->whereColumn('cur_er.school_id', "{$assignment}.school_id")
                    ->whereIn('cur_er.status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
                    ->where('cur_er.starts_on', '<=', $date)
                    ->where(fn ($w) => $w->whereNull('cur_er.ends_on')->orWhere('cur_er.ends_on', '>=', $date))
                    ->where('cur_e.record_status', 'active');
            });
    }

    /**
     * Each given Employee's operational Campus set, in ONE query; an Employee
     * with none (or of another School, or unknown) maps to [].
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<string>> employee id => sorted Campus ids
     */
    public function campusSets(School $school, array $employeeIds, ?string $date = null): array
    {
        // Lower-cased: PostgreSQL returns uuids lower-case, so the result keys match.
        $employeeIds = array_values(array_unique(array_map('strtolower', array_filter($employeeIds, fn (string $id) => Str::isUuid($id)))));
        if ($employeeIds === []) {
            return [];
        }
        $date ??= $this->today($school);

        return $this->context->withSchool($school, function () use ($school, $employeeIds, $date): array {
            $query = DB::table('employee_assignments as ea')
                ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'ea.employment_record_id')->on('er.school_id', '=', 'ea.school_id'))
                ->join('campuses as c', fn ($j) => $j->on('c.id', '=', 'ea.campus_id')->on('c.school_id', '=', 'ea.school_id'))
                ->where('ea.school_id', $school->id)
                ->whereIn('er.employee_id', $employeeIds)
                ->where('c.status', 'active');

            $result = array_fill_keys($employeeIds, []);
            foreach ($this->constrainToCurrent($query, $date)->distinct()->orderBy('ea.campus_id')->get(['er.employee_id', 'ea.campus_id']) as $row) {
                $result[(string) $row->employee_id][] = (string) $row->campus_id;
            }

            return $result;
        });
    }

    /** @return list<string> one Employee's operational Campus ids (sorted) */
    public function campusesFor(School $school, string $employeeId, ?string $date = null): array
    {
        return $this->campusSets($school, [$employeeId], $date)[strtolower($employeeId)] ?? [];
    }

    /**
     * Restricts an `employees` query, in SQL, to Employees operationally
     * present on at least one of $campusIds (an EXISTS predicate: Employee
     * rows only, never hydrated assignment columns). [] matches nothing.
     * Data only: the ORG.7E-1 HR Campus access layer will pass an
     * already-resolved CampusScope's Campus ids here.
     *
     * MUST be called inside the target School's TenantContext.
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>  $campusIds
     * @return Builder<Employee>
     */
    public function whereOperationalOnCampuses(Builder $employees, School $school, array $campusIds, ?string $date = null): Builder
    {
        $campusIds = array_values(array_unique(array_map('strtolower', array_filter($campusIds, fn (string $id) => Str::isUuid($id)))));
        if ($campusIds === []) {
            return $employees->whereRaw('1 = 0');
        }
        $date ??= $this->today($school);

        return $employees->whereExists(function (QueryBuilder $q) use ($school, $campusIds, $date): void {
            $q->selectRaw('1')
                ->from('employee_assignments as ea')
                ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'ea.employment_record_id')->on('er.school_id', '=', 'ea.school_id'))
                ->join('campuses as c', fn ($j) => $j->on('c.id', '=', 'ea.campus_id')->on('c.school_id', '=', 'ea.school_id'))
                ->whereColumn('er.employee_id', 'employees.id')
                ->where('ea.school_id', $school->id)
                ->whereIn('ea.campus_id', $campusIds)
                ->where('c.status', 'active');
            $this->constrainToCurrent($q, $date);
        });
    }
}
