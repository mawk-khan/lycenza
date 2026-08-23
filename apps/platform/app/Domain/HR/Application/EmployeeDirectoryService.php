<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 8A.8 -- the sole read path for the Employee Directory
 * (docs/modules/HR.md "Employee Directory (8A.8, implemented)"). A
 * DISCLOSURE BOUNDARY, not a convenience query wrapper: every result
 * is an `EmployeeDirectoryEntry`, whose own docblock is the complete,
 * exhaustive field contract -- Restricted/Highly-Sensitive tables
 * (`employee_personal_details`, `employee_addresses`,
 * `employee_emergency_contacts`, `employee_qualifications`,
 * `employee_experience_records`, `employee_certifications`,
 * `employee_documents`) are never joined, selected, or eager-loaded
 * anywhere in this class -- not even to compute a count.
 *
 * Deliberately query-code-only -- no new migration, no denormalized
 * `employee_directory` table, no cached materialized view. Organizational
 * placement (Position/Department/Campus/manager) is derived fresh, per
 * request, from the Employee's CURRENTLY EFFECTIVE EmploymentRecord and
 * primary EmployeeAssignment -- "current" meaning `starts_on <= today
 * <= (ends_on OR infinity)`, the exact temporal rule
 * `EmployeeAssignment::isCurrent()`/HR.md's lifecycle matrix already
 * establish, applied here to EmploymentRecord as well (which has no
 * `isCurrent()` helper of its own -- `EmploymentRecord::isOpen()`
 * alone is insufficient: a future-dated open-ended Employment is
 * "open" but not yet "current").
 *
 * Runs entirely under the caller's already-established
 * `TenantContext`/RLS -- no privileged/admin database connection, and
 * every organizational reference lookup (Department/Position/Campus/
 * manager) is scoped by the same tenant boundary as the primary
 * Employee query. A caller-supplied `campusId`/`departmentId`/
 * `positionId` belonging to a DIFFERENT School is never distinguished
 * from one that does not exist at all -- both simply match zero
 * `employee_assignments` rows (RLS + the composite tenant-safe FKs
 * already guarantee this), so no code path here can be used as an
 * existence oracle for another School's data.
 *
 * Batch-hydration, not N+1: this class issues a small, FIXED number
 * of queries per page (one for the page of Employees, one each for
 * current EmploymentRecords/current primary Assignments/Departments/
 * Positions/Campuses/manager Assignments/manager EmploymentRecords/
 * manager Employees) -- never one query per Employee row, regardless
 * of page size.
 *
 * Manager projection is exactly ONE hop -- the current primary
 * Assignment's live `manager_assignment_id` pointer (8A.5), resolved
 * to that manager Assignment's own EmploymentRecord/Employee. No
 * recursive reporting-tree traversal, and no claim of historical
 * point-in-time accuracy -- this reflects 8A.5's live pointer exactly
 * as 8A.5 designed it, nothing more.
 */
class EmployeeDirectoryService
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function search(School $school, EmployeeDirectoryQuery $query): LengthAwarePaginator
    {
        return $this->context->withSchool($school, fn () => $this->runSearch($school, $query));
    }

    private function runSearch(School $school, EmployeeDirectoryQuery $query): LengthAwarePaginator
    {
        $today = Carbon::today()->toDateString();

        $employeeQuery = Employee::query()
            ->where('school_id', $school->id)
            ->when(! $query->includeArchived, fn ($q) => $q->where('record_status', 'active'))
            ->when($query->search !== null && trim($query->search) !== '', function ($q) use ($query) {
                $needle = addcslashes(trim($query->search), '%_\\');
                $q->where(function ($w) use ($needle) {
                    $w->where('employee_number', 'ilike', $needle.'%')
                        ->orWhere('full_name', 'ilike', '%'.$needle.'%');
                });
            })
            ->when(
                $query->campusId !== null || $query->departmentId !== null || $query->positionId !== null,
                fn ($q) => $q->whereExists(function ($sub) use ($school, $query, $today) {
                    $sub->selectRaw('1')
                        ->from('employee_assignments as ea')
                        ->join('employment_records as er', 'er.id', '=', 'ea.employment_record_id')
                        ->whereColumn('er.employee_id', 'employees.id')
                        ->where('ea.school_id', $school->id)
                        ->where('ea.is_primary', true)
                        ->where('ea.starts_on', '<=', $today)
                        ->where(fn ($w) => $w->whereNull('ea.ends_on')->orWhere('ea.ends_on', '>=', $today))
                        ->where('er.starts_on', '<=', $today)
                        ->where(fn ($w) => $w->whereNull('er.ends_on')->orWhere('er.ends_on', '>=', $today))
                        ->when($query->campusId !== null, fn ($w) => $w->where('ea.campus_id', $query->campusId))
                        ->when($query->departmentId !== null, fn ($w) => $w->where('ea.department_id', $query->departmentId))
                        ->when($query->positionId !== null, fn ($w) => $w->where('ea.position_id', $query->positionId));
                }),
            )
            ->orderBy($query->sort, $query->direction)
            ->when($query->sort !== 'employee_number', fn ($q) => $q->orderBy('employee_number'))
            ->orderBy('id');

        $paginator = $employeeQuery->paginate($query->perPage, ['*'], 'page', $query->page);

        $entries = $this->hydrate($school, $paginator->getCollection(), $today);

        return new LengthAwarePaginator(
            $entries,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path()],
        );
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, EmployeeDirectoryEntry>
     */
    private function hydrate(School $school, Collection $employees, string $today): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $employeeIds = $employees->pluck('id')->all();

        $currentEmploymentByEmployeeId = EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->whereIn('employee_id', $employeeIds)
            ->where('starts_on', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group->first());

        $employmentIds = $currentEmploymentByEmployeeId->pluck('id')->all();

        $currentPrimaryAssignmentByEmploymentId = $employmentIds === [] ? collect() : EmployeeAssignment::query()
            ->where('school_id', $school->id)
            ->whereIn('employment_record_id', $employmentIds)
            ->where('is_primary', true)
            ->where('starts_on', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderByDesc('starts_on')
            ->orderBy('id')
            ->get()
            ->groupBy('employment_record_id')
            ->map(fn (Collection $group) => $group->first());

        $departmentIds = $currentPrimaryAssignmentByEmploymentId->pluck('department_id')->filter()->unique()->all();
        $positionIds = $currentPrimaryAssignmentByEmploymentId->pluck('position_id')->filter()->unique()->all();
        $campusIds = $currentPrimaryAssignmentByEmploymentId->pluck('campus_id')->filter()->unique()->all();

        $departmentsById = $departmentIds === [] ? collect() : Department::query()->where('school_id', $school->id)->whereIn('id', $departmentIds)->get()->keyBy('id');
        $positionsById = $positionIds === [] ? collect() : Position::query()->where('school_id', $school->id)->whereIn('id', $positionIds)->get()->keyBy('id');
        $campusesById = $campusIds === [] ? collect() : Campus::query()->where('school_id', $school->id)->whereIn('id', $campusIds)->get()->keyBy('id');

        $managerAssignmentIds = $currentPrimaryAssignmentByEmploymentId->pluck('manager_assignment_id')->filter()->unique()->all();

        $managerAssignmentsById = $managerAssignmentIds === [] ? collect() : EmployeeAssignment::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $managerAssignmentIds)
            ->get()
            ->keyBy('id');

        $managerEmploymentIds = $managerAssignmentsById->pluck('employment_record_id')->unique()->all();

        $managerEmploymentsById = $managerEmploymentIds === [] ? collect() : EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $managerEmploymentIds)
            ->get()
            ->keyBy('id');

        $managerEmployeeIds = $managerEmploymentsById->pluck('employee_id')->unique()->all();

        $managerEmployeesById = $managerEmployeeIds === [] ? collect() : Employee::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $managerEmployeeIds)
            ->get()
            ->keyBy('id');

        return $employees->map(function (Employee $employee) use (
            $currentEmploymentByEmployeeId, $currentPrimaryAssignmentByEmploymentId,
            $departmentsById, $positionsById, $campusesById,
            $managerAssignmentsById, $managerEmploymentsById, $managerEmployeesById,
        ) {
            $employment = $currentEmploymentByEmployeeId->get($employee->id);
            $assignment = $employment ? $currentPrimaryAssignmentByEmploymentId->get($employment->id) : null;

            $department = $assignment?->department_id ? $departmentsById->get($assignment->department_id) : null;
            $position = $assignment?->position_id ? $positionsById->get($assignment->position_id) : null;
            $campus = $assignment?->campus_id ? $campusesById->get($assignment->campus_id) : null;

            $managerAssignment = $assignment?->manager_assignment_id ? $managerAssignmentsById->get($assignment->manager_assignment_id) : null;
            $managerEmployment = $managerAssignment ? $managerEmploymentsById->get($managerAssignment->employment_record_id) : null;
            $managerEmployee = $managerEmployment ? $managerEmployeesById->get($managerEmployment->employee_id) : null;

            return new EmployeeDirectoryEntry(
                employeeId: $employee->id,
                employeeNumber: $employee->employee_number,
                displayName: $employee->full_name,
                positionId: $position?->id,
                positionName: $position?->name,
                departmentId: $department?->id,
                departmentName: $department?->name,
                campusId: $campus?->id,
                campusName: $campus?->name,
                managerEmployeeId: $managerEmployee?->id,
                managerEmployeeNumber: $managerEmployee?->employee_number,
                managerDisplayName: $managerEmployee?->full_name,
            );
        });
    }
}
