<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * ORG.7E-1 (ADR 0076 §43): the given Employees' current assignments, each
     * carrying its `employee_id`, in ONE query. $campusIds null = every
     * current assignment (NULL-Campus and inactive-Campus ones included: the
     * whole-School view); a list = only those on the given ACTIVE Campuses
     * (a Campus-scoped view never sees a NULL-Campus assignment). Ordered by
     * start and id (primary status is display only: callers order by it).
     * MUST be run inside the target
     * School's TenantContext.
     *
     * @param  list<string>  $employeeIds
     * @param  list<string>|null  $campusIds
     * @return Collection<int, EmployeeAssignment>
     */
    public function currentAssignmentsOf(School $school, array $employeeIds, ?array $campusIds = null, ?string $date = null): Collection
    {
        $employeeIds = self::uuids($employeeIds);
        if ($employeeIds === [] || ($campusIds !== null && self::uuids($campusIds) === [])) {
            return new Collection;
        }

        $query = $this->current($school, $date)
            ->join('employment_records as own_er', fn ($j) => $j->on('own_er.id', '=', 'employee_assignments.employment_record_id')->on('own_er.school_id', '=', 'employee_assignments.school_id'))
            ->whereIn('own_er.employee_id', $employeeIds)
            ->select(['employee_assignments.*', 'own_er.employee_id as employee_id']);
        if ($campusIds !== null) {
            $query->whereIn('employee_assignments.campus_id', self::uuids($campusIds))
                ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('campuses as own_c')
                    ->whereColumn('own_c.id', 'employee_assignments.campus_id')
                    ->whereColumn('own_c.school_id', 'employee_assignments.school_id')
                    ->where('own_c.status', 'active'));
        }

        return $query->orderBy('employee_assignments.starts_on')
            ->orderBy('employee_assignments.id')
            ->get();
    }

    /**
     * ORG.7E-1 (ADR 0076 §43, security review L1): which of $assignmentIds are
     * operationally current on one of the given ACTIVE Campuses -- in ONE
     * query (e.g. which reporting managers a Campus-scoped reader may see).
     * MUST be run inside the target School's TenantContext.
     *
     * @param  list<string>  $assignmentIds
     * @param  list<string>  $campusIds
     * @return list<string>
     */
    public function currentAmongOnCampuses(School $school, array $assignmentIds, array $campusIds, ?string $date = null): array
    {
        $assignmentIds = self::uuids($assignmentIds);
        $campusIds = self::uuids($campusIds);
        if ($assignmentIds === [] || $campusIds === []) {
            return [];
        }

        return $this->current($school, $date)
            ->whereIn('employee_assignments.id', $assignmentIds)
            ->whereIn('employee_assignments.campus_id', $campusIds)
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('campuses as own_c')
                ->whereColumn('own_c.id', 'employee_assignments.campus_id')
                ->whereColumn('own_c.school_id', 'employee_assignments.school_id')
                ->where('own_c.status', 'active'))
            ->pluck('employee_assignments.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * ORG.7E-1 (ADR 0076 §43): whether $assignmentId is operationally current
     * on $date (the same rule as current()). MUST be run inside the target
     * School's TenantContext.
     */
    public function isCurrentAssignment(School $school, string $assignmentId, ?string $date = null): bool
    {
        return Str::isUuid($assignmentId) && $this->current($school, $date)->whereKey($assignmentId)->exists();
    }

    /**
     * ORG.7E-1 (ADR 0076 §43): the Employee's operationally current
     * Employment on $date -- an eligible status (`active`, `notice_period`)
     * whose dates cover the date, of an active Employee -- or null. Overlap is
     * refused on write, so there is at most one. MUST be run inside the
     * target School's TenantContext.
     */
    public function currentEmployment(School $school, string $employeeId, ?string $date = null): ?EmploymentRecord
    {
        if (! Str::isUuid($employeeId)) {
            return null;
        }
        $date ??= $this->today($school);

        return EmploymentRecord::query()
            ->where('employment_records.school_id', $school->id)
            ->where('employment_records.employee_id', $employeeId)
            ->whereIn('employment_records.status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
            ->where('employment_records.starts_on', '<=', $date)
            ->where(fn ($w) => $w->whereNull('employment_records.ends_on')->orWhere('employment_records.ends_on', '>=', $date))
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('employees as cur_e')
                ->whereColumn('cur_e.id', 'employment_records.employee_id')
                ->whereColumn('cur_e.school_id', 'employment_records.school_id')
                ->where('cur_e.record_status', 'active'))
            ->orderBy('employment_records.starts_on')
            ->first();
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> the lower-cased UUIDs among $ids
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($ids, fn (string $id) => Str::isUuid($id)))));
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
     * ORG.7E-1: $assignment optionally narrows the matching current
     * assignment further (e.g. its Department or Position, alias `ea`) --
     * never widens it.
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>  $campusIds
     * @param  (callable(QueryBuilder): mixed)|null  $assignment
     * @return Builder<Employee>
     */
    public function whereOperationalOnCampuses(Builder $employees, School $school, array $campusIds, ?string $date = null, ?callable $assignment = null): Builder
    {
        $campusIds = self::uuids($campusIds);
        if ($campusIds === []) {
            return $employees->whereRaw('1 = 0');
        }
        $date ??= $this->today($school);

        return $employees->whereExists(function (QueryBuilder $q) use ($school, $campusIds, $date, $assignment): void {
            $q->selectRaw('1')
                ->from('employee_assignments as ea')
                ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'ea.employment_record_id')->on('er.school_id', '=', 'ea.school_id'))
                ->join('campuses as c', fn ($j) => $j->on('c.id', '=', 'ea.campus_id')->on('c.school_id', '=', 'ea.school_id'))
                ->whereColumn('er.employee_id', 'employees.id')
                ->where('ea.school_id', $school->id)
                ->whereIn('ea.campus_id', $campusIds)
                ->where('c.status', 'active');
            $this->constrainToCurrent($q, $date);
            if ($assignment !== null) {
                $q->where(fn (QueryBuilder $w) => $assignment($w));
            }
        });
    }

    /**
     * ORG.9C-1 (ADR 0076 §69), HR-045: Employees CURRENTLY operational on at
     * least two distinct active Campuses -- counted within $campusIds when
     * given (a Campus reader's scope), so another Campus is never revealed.
     *
     * MUST be called inside the target School's TenantContext.
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>|null  $campusIds  null: every Campus
     * @return Builder<Employee>
     */
    public function whereOnSeveralCampuses(Builder $employees, School $school, ?array $campusIds = null, ?string $date = null): Builder
    {
        if ($campusIds !== null && count(self::uuids($campusIds)) < 2) {
            return $employees->whereRaw('1 = 0');
        }
        $date ??= $this->today($school);
        $campusIds = $campusIds === null ? null : self::uuids($campusIds);

        $bindings = [];
        $count = $this->distinctCampusCount($school, $campusIds, $date, $bindings);

        return $employees->whereRaw('2 <= ('.$count.')', $bindings);
    }

    /**
     * ORG.9C-1, HR-044: Employees with a CURRENT assignment whose manager is a
     * CURRENT assignment of $managerEmployeeId. When $campusIds is given (a
     * Campus reader's scope) BOTH ends must be on those Campuses -- the
     * subordinate's assignment and the manager's -- so a Campus reader never
     * learns of an out-of-scope (or NULL-Campus) assignment or reporting line.
     *
     * MUST be called inside the target School's TenantContext.
     *
     * @param  Builder<Employee>  $employees
     * @param  list<string>|null  $campusIds
     * @return Builder<Employee>
     */
    public function whereReportingTo(Builder $employees, School $school, string $managerEmployeeId, ?array $campusIds = null, ?string $date = null): Builder
    {
        $managerCampusIds = $campusIds === null ? null : self::uuids($campusIds);
        if (! Str::isUuid($managerEmployeeId) || $managerCampusIds === []) {
            return $employees->whereRaw('1 = 0');
        }
        $date ??= $this->today($school);

        return $employees->whereExists(function (QueryBuilder $q) use ($school, $managerEmployeeId, $managerCampusIds, $date): void {
            $q->selectRaw('1')
                ->from('employee_assignments as ea')
                ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'ea.employment_record_id')->on('er.school_id', '=', 'ea.school_id'))
                ->whereColumn('er.employee_id', 'employees.id')
                ->where('ea.school_id', $school->id)
                ->when($managerCampusIds !== null, fn ($w) => $w->whereIn('ea.campus_id', $managerCampusIds ?? []))
                ->whereExists(function (QueryBuilder $m) use ($school, $managerEmployeeId, $managerCampusIds, $date): void {
                    $m->selectRaw('1')
                        ->from('employee_assignments as ma')
                        ->join('employment_records as mer', fn ($j) => $j->on('mer.id', '=', 'ma.employment_record_id')->on('mer.school_id', '=', 'ma.school_id'))
                        ->whereColumn('ma.id', 'ea.manager_assignment_id')
                        ->where('ma.school_id', $school->id)
                        ->where('mer.employee_id', $managerEmployeeId)
                        ->when($managerCampusIds !== null, fn ($w) => $w->whereIn('ma.campus_id', $managerCampusIds ?? []));
                    $this->constrainToCurrent($m, $date, 'ma');
                });
            $this->constrainToCurrent($q, $date);
        });
    }

    /**
     * SQL counting an Employee's distinct current active Campuses (correlated
     * on `employees.id`), appending its bindings to $bindings.
     *
     * @param  list<string>|null  $campusIds
     * @param  array<int, mixed>  $bindings
     *
     * @param-out array<int, mixed> $bindings
     */
    private function distinctCampusCount(School $school, ?array $campusIds, string $date, array &$bindings): string
    {
        $q = DB::table('employee_assignments as ea')
            ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'ea.employment_record_id')->on('er.school_id', '=', 'ea.school_id'))
            ->join('campuses as c', fn ($j) => $j->on('c.id', '=', 'ea.campus_id')->on('c.school_id', '=', 'ea.school_id'))
            ->whereColumn('er.employee_id', 'employees.id')
            ->where('ea.school_id', $school->id)
            ->where('c.status', 'active')
            ->when($campusIds !== null, fn ($w) => $w->whereIn('ea.campus_id', $campusIds ?? []))
            ->selectRaw('count(DISTINCT ea.campus_id)');
        $this->constrainToCurrent($q, $date);
        $bindings = $q->getBindings();

        return $q->toSql();
    }
}
