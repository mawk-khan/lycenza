<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * ORG.7E-1 (ADR 0076 §43): the Campus-safe Employee OPERATIONAL OVERVIEW --
 * a deliberately minimized read model, NOT the Employee Profile (which stays
 * behind whole-School `hr.employees.personal.view`).
 *
 * - Entry: `hr.employees.view` (Campus-adopted); the Employee must be
 *   reachable in that scope (HrEmployeeAccess), else NOT FOUND.
 * - Summary: id, employee number, display name, archived flag, and the
 *   placement of an in-scope current assignment (primary first) -- the same
 *   Directory-tier fields, never another Campus's placement.
 * - Current assignments: only with `hr.employees.assignments.view`, and only
 *   CURRENT ones within that scope (all current ones for the whole School).
 *   No history, no employment details, no NULL-Campus assignment for a
 *   Campus-scoped reader.
 * - Never: work or personal contact data, addresses, date of birth,
 *   identifiers, qualifications, documents, notes, pay, leave, attendance or
 *   account/security data. No table holding them is read here.
 */
class EmployeeOperationalOverviewService
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function forEmployee(School $school, string $employeeId, User $actor): array
    {
        $view = $this->authorizeCampusScopeFor($actor, 'hr.employees.view', $school);
        $assignmentsScope = $this->access->scopeOf($actor, 'hr.employees.assignments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $employeeId, $view, $assignmentsScope): array {
            $employee = $this->access->employee($school, $view, $employeeId);
            $placement = $this->access->currentAssignments($school, $view, $employee->id)->first();
            $assignments = $assignmentsScope->isNone() ? null : $this->access->currentAssignments($school, $assignmentsScope, $employee->id);
            $all = $placement === null ? $assignments ?? new Collection : (new Collection([$placement]))->merge($assignments ?? []);
            // Security review L1: a scoped reader sees a manager only when the
            // manager's own assignment is current on one of the reader's Campuses.
            $visibleManagers = $view->isWholeSchool() ? null
                : app(CurrentEmployeeAssignments::class)->currentAmongOnCampuses($school, $all->pluck('manager_assignment_id')->filter()->unique()->values()->all(), $view->campusIds());
            $labels = $this->labels($school, $all, $visibleManagers);

            return [
                'employee' => [
                    'id' => $employee->id,
                    'employeeNumber' => $employee->employee_number,
                    'displayName' => $employee->full_name,
                    'isArchived' => $employee->record_status === 'archived',
                ],
                'placement' => $placement === null ? null : $this->present($placement, $labels),
                // null = the reader may not see assignments at all (no section).
                'currentAssignments' => $assignments?->map(fn (EmployeeAssignment $a) => $this->present($a, $labels))->values()->all(),
            ];
        });
    }

    /**
     * @param  array{positions: \Illuminate\Support\Collection<string, string>, departments: \Illuminate\Support\Collection<string, string>, campuses: \Illuminate\Support\Collection<string, string>, managers: array<string, string>}  $labels
     * @return array<string, mixed>
     */
    private function present(EmployeeAssignment $a, array $labels): array
    {
        return [
            'id' => $a->id,
            'employmentRecordId' => $a->employment_record_id,
            'positionName' => $labels['positions'][$a->position_id] ?? null,
            'departmentName' => $a->department_id === null ? null : ($labels['departments'][$a->department_id] ?? null),
            'campusId' => $a->campus_id,
            'campusName' => $a->campus_id === null ? null : ($labels['campuses'][$a->campus_id] ?? null),
            'isPrimary' => (bool) $a->is_primary,
            'startsOn' => $a->starts_on->toDateString(),
            'endsOn' => $a->ends_on?->toDateString(),
            'managerName' => $a->manager_assignment_id === null ? null : ($labels['managers'][$a->manager_assignment_id] ?? null),
        ];
    }

    /**
     * Batch label lookups (a fixed number of queries, never one per row).
     * The manager is one hop: the assignment's live manager pointer, by name.
     *
     * @param  Collection<int, EmployeeAssignment>  $assignments
     * @param  list<string>|null  $visibleManagers  null = every manager (whole School)
     * @return array{positions: \Illuminate\Support\Collection<string, string>, departments: \Illuminate\Support\Collection<string, string>, campuses: \Illuminate\Support\Collection<string, string>, managers: array<string, string>}
     */
    private function labels(School $school, Collection $assignments, ?array $visibleManagers = null): array
    {
        $managerIds = $assignments->pluck('manager_assignment_id')->filter()->unique()
            ->when($visibleManagers !== null, fn ($ids) => $ids->intersect($visibleManagers))->values()->all();
        $managers = [];
        if ($managerIds !== []) {
            $managerAssignments = EmployeeAssignment::query()->where('school_id', $school->id)->whereIn('id', $managerIds)->get(['id', 'employment_record_id']);
            $employeeIdByEmployment = EmploymentRecord::query()->where('school_id', $school->id)->whereIn('id', $managerAssignments->pluck('employment_record_id'))->pluck('employee_id', 'id');
            $names = Employee::query()->where('school_id', $school->id)->whereIn('id', $employeeIdByEmployment->values())->pluck('full_name', 'id');
            foreach ($managerAssignments as $m) {
                $name = $names[$employeeIdByEmployment[$m->employment_record_id] ?? ''] ?? null;
                if ($name !== null) {
                    $managers[(string) $m->id] = $name;
                }
            }
        }

        return [
            'positions' => Position::query()->where('school_id', $school->id)->whereIn('id', $assignments->pluck('position_id')->unique())->pluck('name', 'id'),
            'departments' => Department::query()->where('school_id', $school->id)->whereIn('id', $assignments->pluck('department_id')->filter()->unique())->pluck('name', 'id'),
            'campuses' => Campus::query()->where('school_id', $school->id)->whereIn('id', $assignments->pluck('campus_id')->filter()->unique())->pluck('name', 'id'),
            'managers' => $managers,
        ];
    }
}
