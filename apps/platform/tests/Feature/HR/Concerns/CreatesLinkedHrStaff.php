<?php

namespace Tests\Feature\HR\Concerns;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAccountLinkCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/** HR.R1-C8: someone who may take part in a link correction: whole-School HR, linked to their own employed Employee. */
trait CreatesLinkedHrStaff
{
    protected function linkedHrStaff(School $school, array $capabilities = ['hr.employees.manage']): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $own = $this->createEmployee($school, ['user_id' => $user->id]);
        $this->createEmploymentRecord($own, ['status' => 'active', 'starts_on' => '2014-01-01', 'ends_on' => null]);

        return $user;
    }

    protected function requestCorrection(Employee $employee, string $linkId, User $requester, EmployeeAccountLinkVoidReason $reason = EmployeeAccountLinkVoidReason::WrongPerson): EmployeeAccountLinkCorrection
    {
        return app(TenantContext::class)->withSchool($employee->school, fn () => app(EmployeeAccountLinkCorrectionService::class)->request($employee->fresh(), $linkId, $reason, $requester));
    }

    protected function approveCorrection(School $school, EmployeeAccountLinkCorrection $correction, User $reviewer, ?string $version = null): EmployeeAccountLinkCorrection
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(EmployeeAccountLinkCorrectionService::class)->approve(Employee::query()->findOrFail($correction->employee_id), $correction->id, $version ?? app(EmployeeAccountLinkCorrectionService::class)->version($school->id, $correction->account_link_id), $reviewer, new FreshMfaProof($reviewer->id, CarbonImmutable::now())));
    }

    protected function refuseCorrection(School $school, EmployeeAccountLinkCorrection $correction, User $reviewer): EmployeeAccountLinkCorrection
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(EmployeeAccountLinkCorrectionService::class)->refuse(Employee::query()->findOrFail($correction->employee_id), $correction->id, $reviewer, new FreshMfaProof($reviewer->id, CarbonImmutable::now())));
    }

    /** Two fresh linked staff, end to end. */
    protected function correctLink(Employee $employee, string $linkId, EmployeeAccountLinkVoidReason $reason = EmployeeAccountLinkVoidReason::WrongPerson): EmployeeAccountLink
    {
        $school = $employee->school;
        $correction = $this->requestCorrection($employee, $linkId, $this->linkedHrStaff($school), $reason);
        $this->approveCorrection($school, $correction, $this->linkedHrStaff($school));

        return app(TenantContext::class)->withSchool($school, fn () => EmployeeAccountLink::query()->findOrFail($linkId));
    }
}
