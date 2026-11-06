<?php

namespace App\Domain\HR\Application\AccountLinks;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkAlreadyCorrectedException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Concurrency\SchoolCompensationLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HR.R1-C7 (OD-HR-25): the one way to say a link was never true. The row stays,
 * marked, with who corrected it, when and why, so a wrong link leaves no permanent
 * false conflict and a true one cannot vanish quietly. A current link is unlinked in
 * the same transaction. A decision already recorded keeps what it recorded.
 *
 * `hr.employees.manage`, whole-School, read fresh under the lock, the actor's own
 * fresh-MFA proof, a closed reason, and never by anyone linked to that Employee. Compensation lock
 * first, as for every link writer, then the Employee row.
 */
final class EmployeeAccountLinkCorrectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly EmployeeService $employees,
        private readonly AuditRecorder $audit,
    ) {}

    public function correct(Employee $employee, string $linkId, EmployeeAccountLinkVoidReason $reason, User $actor, FreshMfaProof $mfa): EmployeeAccountLink
    {
        $school = $employee->school;
        $this->access->requireWholeSchool($actor, 'hr.employees.manage', $school);
        if (! $mfa->isFor($actor->id)) {
            throw new LogicException('The fresh MFA proof belongs to another User.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employee, $linkId, $reason, $actor): EmployeeAccountLink {
            SchoolCompensationLock::acquire($school->id, shared: false);
            $this->authority->requireCurrent($actor, 'hr.employees.manage', $school);
            $locked = Employee::query()->where('school_id', $school->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $link = EmployeeAccountLink::query()->where('school_id', $school->id)->where('employee_id', $locked->id)->whereKey($linkId)->firstOrFail();

            // Not only this row: an account ever tied to this Employee may be that person's other account.
            if ($this->selfAdministration->hasAnyLink($actor, $school->id, $locked->id)) {
                throw new HrSelfAdministrationException('correct');
            }
            if ($link->voided_at !== null) {
                throw new EmployeeAccountLinkAlreadyCorrectedException($link->id);
            }

            // Through the one writer of the current link, so a current link is an ordinary unlink first.
            $wasCurrent = $link->ended_at === null;
            if ($wasCurrent) {
                $this->employees->unlinkUser($locked, $actor);
            }
            DB::select('SELECT employee_account_link_void(?, ?, ?, ?)', [$school->id, $link->id, $reason->value, $actor->id]);

            $this->audit->school($school, 'employee.user_link_corrected', actor: $actor, subject: $locked, metadata: [
                'employeeId' => $locked->id,
                'linkId' => $link->id,
                'userId' => $link->user_id,
                'wasCurrent' => $wasCurrent,
                'reason' => $reason->value,
            ]);

            return $link->fresh();
        }));
    }
}
