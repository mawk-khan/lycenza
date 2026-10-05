<?php

namespace Tests\Feature\Leave\Concerns;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * HRX.1: a School with a Leave administrator, a leave type, a policy, an
 * opened leave year and a current employment with the policy assigned.
 */
trait CreatesLeaveFixtures
{
    use CreatesTenancyFixtures;

    protected function leaveAdmin(School $school): User
    {
        return $this->createUserWithCapabilities($school, ['hr.leave.configure', 'hr.leave.view', 'hr.leave.manage']);
    }

    protected function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    protected function leaveType(School $school, User $admin, string $code = 'CL', bool $paid = true, bool $tracked = true, bool $halfDay = true): LeaveType
    {
        return app(LeaveTypeService::class)->create($school, $code, "{$code} leave", $paid, $tracked, $halfDay, $admin);
    }

    /** @param  array<string, mixed>  $terms */
    protected function leavePolicy(School $school, LeaveType $type, User $admin, array $terms = []): LeavePolicy
    {
        return app(LeavePolicyService::class)->create($school, $type->id, $terms + [
            'name' => 'Standard', 'annual_allocation_units' => 24, 'carry_forward_allowed' => true,
            'carry_forward_cap_units' => 10, 'carry_forward_expiry_days' => 90,
        ], null, $admin);
    }

    protected function currentEmployment(School $school, string $startsOn = '2024-01-01', string $status = 'active'): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($school), ['status' => $status, 'starts_on' => $startsOn, 'ends_on' => null]);
    }

    protected function leaveYear(School $school, User $admin, string $on = '2026-06-01'): LeaveYear
    {
        return app(LeaveYearService::class)->open($school, $on, $admin);
    }

    /** HRX.2: Monday-Friday full, Saturday morning only, Sunday off. */
    protected function workingWeek(School $school, User $admin): void
    {
        app(StaffCalendarService::class)->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'first_half', 7 => 'off'], $admin);
    }

    /**
     * HRX.2: a staff member -- a User with these capabilities, linked to an
     * active Employee with a current employment and one open assignment.
     *
     * @param  list<string>  $capabilities
     * @return array{user: User, employment: EmploymentRecord, assignment: EmployeeAssignment}
     */
    protected function staffMember(School $school, array $capabilities = [], string $startsOn = '2024-01-01'): array
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $employment = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => $startsOn, 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($school), ['starts_on' => $startsOn, 'ends_on' => null, 'is_primary' => true]);

        return compact('user', 'employment', 'assignment');
    }

    /** HRX.2: point the subordinate's assignment at the manager's (raw, fixture-only). */
    protected function reportTo(EmployeeAssignment $subordinate, ?EmployeeAssignment $manager): void
    {
        $this->inSchool($subordinate->school, fn () => EmployeeAssignment::query()->whereKey($subordinate->id)->update(['manager_assignment_id' => $manager?->id]));
    }

    protected function allocate(array $w, int $units = 24, ?LeaveYear $year = null, ?EmploymentRecord $employment = null): void
    {
        app(LeaveLedgerService::class)->allocate($w['school'], ($employment ?? $w['employment'])->id, $w['type']->id, ($year ?? $w['year'])->id, $units, $w['admin']);
    }

    protected function submitLeave(array $w, string $from, string $to, string $startPortion = 'full', ?string $endPortion = null, ?EmploymentRecord $employment = null, ?LeaveType $type = null): LeaveRequest
    {
        return app(LeaveRequestService::class)->submitOnBehalf($w['school'], ($employment ?? $w['employment'])->id, ($type ?? $w['type'])->id, $from, $startPortion, $to, $endPortion ?? $startPortion, null, $w['admin']);
    }

    /** @return array{school: School, admin: User, type: LeaveType, policy: LeavePolicy, year: LeaveYear, employment: EmploymentRecord} */
    protected function leaveWorld(): array
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $policy = $this->leavePolicy($school, $type, $admin);
        $year = $this->leaveYear($school, $admin);
        $employment = $this->currentEmployment($school);
        app(LeavePolicyAssignmentService::class)->assign($school, $employment->id, $policy->id, '2026-04-01', null, $admin);

        return compact('school', 'admin', 'type', 'policy', 'year', 'employment');
    }
}
