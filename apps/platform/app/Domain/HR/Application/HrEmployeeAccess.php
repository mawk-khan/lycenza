<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * ORG.7E-1 (ADR 0076 §43): the ONE place HR reads or targets Employees,
 * employments and assignments under the actor's CampusScope for the adopted
 * operational capabilities (`hr.employees.view`, `.manage`,
 * `.assignments.view`, `.assignments.manage`). Built on the canonical
 * CurrentEmployeeAssignments definition; it never judges currency itself.
 *
 * - Whole School: every existing behaviour, unchanged.
 * - Campus set: an Employee is reachable only when at least one CURRENT
 *   assignment is on an active Campus in scope (an SQL EXISTS before any row
 *   is returned). Unassigned, archived, not-yet-started and ended Employees
 *   are whole-School only. Several Campus grants are a union.
 * - Reaching an Employee is not seeing all of them: a Campus-scoped actor
 *   sees and targets only CURRENT assignments on in-scope Campuses -- never
 *   a NULL-Campus one, another Campus's, or history -- and only the current
 *   employment.
 * - None: nothing.
 * - Out of scope is NOT FOUND (404), exactly like another School's record.
 *
 * Writes go through HrEmployeeCampusGuard (writeGuard()), inside the
 * service's transaction, after EmployeePlacementLock.
 */
final class HrEmployeeAccess
{
    public function __construct(
        private readonly CurrentEmployeeAssignments $current,
        private readonly CampusScopeResolver $scopes,
    ) {}

    /**
     * Restricts an Employee query to the scope, in SQL.
     *
     * @return Builder<Employee>
     */
    public function employees(School $school, CampusScope $scope): Builder
    {
        /** @var Builder<Employee> $query */
        $query = Employee::query()->where('employees.school_id', $school->id);
        if ($scope->isWholeSchool()) {
            return $query;
        }

        return $this->current->whereOperationalOnCampuses($query, $school, $scope->campusIds());
    }

    /** The Employee when the scope reaches it, else NOT FOUND (unknown, other School and out of scope alike). */
    public function employee(School $school, CampusScope $scope, string $id): Employee
    {
        $found = Str::isUuid($id) ? $this->employees($school, $scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(Employee::class, [$id]);
    }

    public function coversEmployee(School $school, CampusScope $scope, string $id): bool
    {
        return Str::isUuid($id) && $this->employees($school, $scope)->whereKey($id)->exists();
    }

    /** @return list<string>|null the scope's Campus ids; null for the whole School */
    public function authorizedCampusSet(CampusScope $scope): ?array
    {
        return $scope->isWholeSchool() ? null : $scope->campusIds();
    }

    /**
     * An employment record. A Campus-scoped actor reaches only the CURRENT
     * employment of an Employee in scope (history stays whole-School).
     */
    public function employment(School $school, CampusScope $scope, string $id): EmploymentRecord
    {
        $found = Str::isUuid($id) ? EmploymentRecord::query()->where('school_id', $school->id)->find($id) : null;
        if ($found !== null && ! $scope->isWholeSchool()
            && ($this->current->currentEmployment($school, $found->employee_id)?->id !== $found->id || ! $this->coversEmployee($school, $scope, $found->employee_id))) {
            $found = null;
        }

        return $found ?? throw (new ModelNotFoundException)->setModel(EmploymentRecord::class, [$id]);
    }

    /**
     * An assignment. A Campus-scoped actor reaches only a CURRENT assignment
     * on an active Campus in scope -- never a NULL-Campus or ended one.
     */
    public function assignment(School $school, CampusScope $scope, string $id): EmployeeAssignment
    {
        $found = Str::isUuid($id) ? EmployeeAssignment::query()->where('school_id', $school->id)->find($id) : null;
        if ($found !== null && ! $scope->isWholeSchool() && ! $this->inScopeCurrent($school, $scope, $found)) {
            $found = null;
        }

        return $found ?? throw (new ModelNotFoundException)->setModel(EmployeeAssignment::class, [$id]);
    }

    /**
     * The Employee's current assignments the scope may see: all of them for
     * the whole School, in-scope active-Campus ones for a Campus set.
     *
     * @return Collection<int, EmployeeAssignment>
     */
    public function currentAssignments(School $school, CampusScope $scope, string $employeeId): Collection
    {
        if ($scope->isNone()) {
            return new Collection;
        }

        // Primary first (display order only), then start and id.
        return $this->current->currentAssignmentsOf($school, [$employeeId], $this->authorizedCampusSet($scope))
            ->sortByDesc(fn (EmployeeAssignment $a) => (int) $a->is_primary)->values();
    }

    /** The Employee's current employment (null when none, or out of scope). */
    public function currentEmployment(School $school, CampusScope $scope, string $employeeId): ?EmploymentRecord
    {
        return $this->coversEmployee($school, $scope, $employeeId) ? $this->current->currentEmployment($school, $employeeId) : null;
    }

    /**
     * Whether the scope covers EVERY current Campus of the Employee and at
     * least one (a read-only preview of HrEmployeeCampusGuard's identity
     * rule, for page affordances; never write authority).
     */
    public function coversAllCurrentCampuses(School $school, CampusScope $scope, string $employeeId): bool
    {
        if ($scope->isWholeSchool()) {
            return true;
        }
        $campuses = $this->current->campusesFor($school, $employeeId);

        return $campuses !== [] && array_diff($campuses, $scope->campusIds()) === [] && ! $this->hasCurrentUnplacedAssignment($school, $employeeId);
    }

    /**
     * Active Campuses offered to the scope (in-scope ones for a Campus set).
     *
     * @return list<array{id: string, name: string}>
     */
    public function campusOptions(School $school, CampusScope $scope): array
    {
        return $scope->restrict(Campus::query()->where('school_id', $school->id)->where('status', 'active'), 'campuses.id')
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Campus $c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->all();
    }

    /**
     * Active Departments a scoped assignment may name: School-wide ones and
     * those of an in-scope Campus (read-only reference labels; never
     * Department configuration authority).
     *
     * @return list<array{id: string, name: string, campusId: string|null}>
     */
    public function departmentOptions(School $school, CampusScope $scope): array
    {
        return Department::query()->where('school_id', $school->id)->where('status', 'active')
            ->when(! $scope->isWholeSchool(), fn ($q) => $q->where(fn ($w) => $w->whereNull('campus_id')->orWhereIn('campus_id', $scope->campusIds())))
            ->orderBy('name')->get(['id', 'name', 'campus_id'])
            ->map(fn (Department $d) => ['id' => (string) $d->id, 'name' => $d->name, 'campusId' => $d->campus_id])->values()->all();
    }

    /** The write-time checks for a Campus-scoped actor; none for the whole School (unchanged path). */
    public function writeGuard(School $school, CampusScope $scope): ?HrEmployeeCampusGuard
    {
        return $scope->isWholeSchool() ? null : new HrEmployeeCampusGuard($school, $scope, $this->current);
    }

    /** The actor's scope for a capability (page affordances; every write re-checks). */
    public function scopeOf(User $actor, string $capability, School $school): CampusScope
    {
        return $this->scopes->campusScope($actor, $capability, $school);
    }

    public function holdsWholeSchool(User $actor, string $capability, School $school): bool
    {
        return $this->scopeOf($actor, $capability, $school)->isWholeSchool();
    }

    /**
     * A declared whole-School-only HR operation (ADR 0076 §43): the ordinary
     * `capability` Gate, which a Campus-scoped grant never satisfies. The
     * route carries the same whole-School floor
     * (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS); this is the service's
     * own defence in depth.
     */
    public function requireWholeSchool(User $actor, string $capability, School $school): void
    {
        Gate::forUser($actor)->authorize('capability', [$capability, $school]);
    }

    private function inScopeCurrent(School $school, CampusScope $scope, EmployeeAssignment $assignment): bool
    {
        $campusId = $assignment->campus_id;

        return is_string($campusId) && $scope->covers($campusId)
            && Campus::query()->where('school_id', $school->id)->whereKey($campusId)->where('status', 'active')->exists()
            && $this->current->isCurrentAssignment($school, $assignment->id);
    }

    /** A current NULL-Campus assignment is School-wide placement: whole-School authority only. */
    public function hasCurrentUnplacedAssignment(School $school, string $employeeId): bool
    {
        return $this->current->currentAssignmentsOf($school, [$employeeId])->contains(fn (EmployeeAssignment $a) => $a->campus_id === null);
    }
}
