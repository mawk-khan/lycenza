<?php

namespace Tests\Feature\Leave\Concerns;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Infrastructure\LeavePolicy;
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
