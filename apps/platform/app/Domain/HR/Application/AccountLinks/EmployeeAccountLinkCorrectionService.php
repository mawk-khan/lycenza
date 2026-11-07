<?php

namespace App\Domain\HR\Application\AccountLinks;

use App\Domain\HR\Application\CurrentStaffMembers;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkAlreadyCorrectedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionAlreadyDecidedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionAlreadyRequestedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionNotIndependentException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionNotQualifiedException;
use App\Domain\HR\Application\Exceptions\EmployeeAccountLinkCorrectionStaleException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAccountLinkCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Approval\ApprovalParticipants;
use App\Support\Approval\ApprovalProvenance;
use App\Support\Approval\MakerCheckerPolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Concurrency\SchoolCompensationLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * HR.R1-C8 (OD-HR-25 as amended by the owner): saying a link was never true takes two
 * linked staff. One requests, which changes nothing. Another decides with a fresh
 * code, and an approval voids the link in the same transaction, through the database
 * trigger that is the only way to void one. Until then the link keeps counting for
 * Payroll, and on a refusal it simply stands.
 *
 * Both need whole-School `hr.employees.manage`, read fresh for a decision, must be
 * CurrentStaffMembers (a link to their own active Employee and a current employment),
 * and must never have been linked to this Employee. A never-linked account cannot take
 * part. This is separation of duties: two accounts are not proof of two people, and
 * collusion stays possible, so it is recorded and audited, never claimed closed.
 *
 * The decision takes the compensation lock first, as every link writer does, then the
 * Employee row, then this row.
 */
final class EmployeeAccountLinkCorrectionService
{
    public const WORKFLOW = 'employee_account_link_corrections';

    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly EmployeeService $employees,
        private readonly CurrentStaffMembers $staff,
        private readonly MakerCheckerPolicy $makerChecker,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(Employee $employee, string $linkId, EmployeeAccountLinkVoidReason $reason, User $actor): EmployeeAccountLinkCorrection
    {
        $school = $employee->school;
        $this->access->requireWholeSchool($actor, 'hr.employees.manage', $school);

        try {
            return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employee, $linkId, $reason, $actor): EmployeeAccountLinkCorrection {
                $locked = Employee::query()->where('school_id', $school->id)->whereKey($employee->id)->sharedLock()->firstOrFail();
                $link = EmployeeAccountLink::query()->where('school_id', $school->id)->where('employee_id', $locked->id)->whereKey($linkId)->firstOrFail();
                if ($link->voided_at !== null) {
                    throw new EmployeeAccountLinkAlreadyCorrectedException($link->id);
                }
                $this->requireQualified($actor, $school, $locked->id);
                if ($this->knownSamePerson($school->id, $link->user_id, $actor->id)) {
                    throw new EmployeeAccountLinkCorrectionNotIndependentException($link->id);
                }

                $correction = new EmployeeAccountLinkCorrection;
                $correction->forceFill([
                    'id' => (string) Str::uuid7(),
                    'school_id' => $school->id,
                    'employee_id' => $locked->id,
                    'account_link_id' => $link->id,
                    'reason' => $reason->value,
                    'evidence_version' => $this->version($school->id, $link->id),
                    'status' => EmployeeAccountLinkCorrection::REQUESTED,
                    'requested_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'employee.user_link_correction_requested', actor: $actor, subject: $locked, metadata: [
                    'correctionId' => $correction->id,
                    'employeeId' => $locked->id,
                    'linkId' => $link->id,
                    'userId' => $link->user_id,
                    'reason' => $reason->value,
                    'evidenceVersion' => $correction->evidence_version,
                ]);

                return $correction;
            }));
        } catch (UniqueConstraintViolationException) {
            throw new EmployeeAccountLinkCorrectionAlreadyRequestedException($linkId);
        }
    }

    public function approve(Employee $employee, string $correctionId, string $reviewedVersion, User $actor, FreshMfaProof $mfa): EmployeeAccountLinkCorrection
    {
        $school = $employee->school;

        return $this->decide($employee, $correctionId, EmployeeAccountLinkCorrection::APPROVED, $actor, $mfa, function (EmployeeAccountLinkCorrection $correction, Employee $employee, EmployeeAccountLink $link) use ($school, $reviewedVersion, $actor): void {
            $this->assertMayDecide($school, $correction, $employee, $link, $actor);
            // Checked at the request too, but an alias can appear while it waits. It can still be refused.
            if ($this->knownSamePerson($school->id, $correction->requested_by_user_id, $link->user_id)) {
                throw new EmployeeAccountLinkCorrectionNotIndependentException($correction->id);
            }

            $current = $this->version($school->id, $link->id);
            if (! hash_equals($current, $correction->evidence_version) || ! hash_equals($current, $reviewedVersion)) {
                throw new EmployeeAccountLinkCorrectionStaleException($correction->id);
            }
            // Through the one writer of the current link, so a current link is an ordinary unlink first.
            if ($link->ended_at === null) {
                $this->employees->unlinkUser($employee, $actor);
            }
        });
    }

    /** A stale request can always be refused: that is how it stops waiting. */
    public function refuse(Employee $employee, string $correctionId, User $actor, FreshMfaProof $mfa): EmployeeAccountLinkCorrection
    {
        $school = $employee->school;

        return $this->decide($employee, $correctionId, EmployeeAccountLinkCorrection::REFUSED, $actor, $mfa, function (EmployeeAccountLinkCorrection $correction, Employee $employee, EmployeeAccountLink $link) use ($school, $actor): void {
            $this->assertMayDecide($school, $correction, $employee, $link, $actor);
        });
    }

    /** @param callable(EmployeeAccountLinkCorrection, Employee, EmployeeAccountLink): void $check under every lock */
    private function decide(Employee $routeEmployee, string $correctionId, string $decision, User $actor, FreshMfaProof $mfa, callable $check): EmployeeAccountLinkCorrection
    {
        $school = $routeEmployee->school;
        $this->access->requireWholeSchool($actor, 'hr.employees.manage', $school);
        if (! $mfa->isFor($actor->id)) {
            throw new LogicException('The fresh MFA proof belongs to another User.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $routeEmployee, $correctionId, $decision, $actor, $mfa, $check): EmployeeAccountLinkCorrection {
            SchoolCompensationLock::acquire($school->id, shared: false);
            $this->authority->requireCurrent($actor, 'hr.employees.manage', $school);
            // This Employee's request or nothing: the route names both.
            $employee = Employee::query()->where('school_id', $school->id)->whereKey($routeEmployee->id)->lockForUpdate()->firstOrFail();
            $correction = EmployeeAccountLinkCorrection::query()->where('school_id', $school->id)->where('employee_id', $employee->id)->whereKey($correctionId)->lockForUpdate()->firstOrFail();
            $link = EmployeeAccountLink::query()->where('school_id', $school->id)->whereKey($correction->account_link_id)->firstOrFail();
            $wasCurrent = $link->ended_at === null;

            if ($correction->status !== EmployeeAccountLinkCorrection::REQUESTED) {
                throw new EmployeeAccountLinkCorrectionAlreadyDecidedException($correction->id);
            }
            $check($correction, $employee, $link);

            // decided_at is the database's own clock (the guard sets it).
            $correction->forceFill(['status' => $decision, 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();

            $approved = $decision === EmployeeAccountLinkCorrection::APPROVED;
            $this->audit->school($school, $approved ? 'employee.user_link_corrected' : 'employee.user_link_correction_refused', actor: $actor, subject: $employee, metadata: [
                'correctionId' => $correction->id,
                'employeeId' => $employee->id,
                'linkId' => $link->id,
                'userId' => $link->user_id,
                'wasCurrent' => $approved && $wasCurrent,
                'reason' => $correction->reason,
                'evidenceVersion' => $correction->evidence_version,
                ...ApprovalProvenance::metadata(self::participants($correction), $approved ? ApprovalProvenance::APPROVED : ApprovalProvenance::REJECTED, $actor, 'school', $mfa),
            ]);

            return $correction;
        }));
    }

    private function assertMayDecide(School $school, EmployeeAccountLinkCorrection $correction, Employee $employee, EmployeeAccountLink $link, User $actor): void
    {
        if (! $this->makerChecker->permits($actor, self::participants($correction))
            || $this->knownSamePerson($school->id, $correction->requested_by_user_id, $actor->id)
            || $this->knownSamePerson($school->id, $link->user_id, $actor->id)) {
            throw new EmployeeAccountLinkCorrectionNotIndependentException($correction->id);
        }
        $this->requireQualified($actor, $school, $employee->id);
    }

    /** For the page. The service decides again under its locks. */
    public function mayRequest(User $actor, School $school, EmployeeAccountLink $link): bool
    {
        return ! $this->selfAdministration->hasAnyLink($actor, $school->id, $link->employee_id)
            && $this->staff->includes($school, $actor)
            && ! $this->knownSamePerson($school->id, $link->user_id, $actor->id);
    }

    public function mayDecide(User $actor, School $school, EmployeeAccountLinkCorrection $correction, EmployeeAccountLink $link): bool
    {
        return $correction->requested_by_user_id !== $actor->id
            && ! $this->knownSamePerson($school->id, $correction->requested_by_user_id, $actor->id)
            && $this->mayRequest($actor, $school, $link);
    }

    public function version(string $schoolId, string $linkId): string
    {
        return (string) DB::selectOne('SELECT employee_account_link_correction_version(?, ?) AS version', [$schoolId, $linkId])->version;
    }

    private function requireQualified(User $actor, School $school, string $employeeId): void
    {
        if ($this->selfAdministration->hasAnyLink($actor, $school->id, $employeeId)) {
            throw new HrSelfAdministrationException('correct');
        }
        if (! $this->staff->includes($school, $actor)) {
            throw new EmployeeAccountLinkCorrectionNotQualifiedException;
        }
    }

    /** Two accounts with uncorrected links to one Employee are one person, as far as anything here can know. */
    private function knownSamePerson(string $schoolId, string $firstUserId, string $secondUserId): bool
    {
        return EmployeeAccountLink::query()->where('school_id', $schoolId)->where('user_id', $firstUserId)->whereNull('voided_at')
            ->whereIn('employee_id', EmployeeAccountLink::query()->where('school_id', $schoolId)->where('user_id', $secondUserId)->whereNull('voided_at')->select('employee_id'))
            ->exists();
    }

    public static function participants(EmployeeAccountLinkCorrection $correction): ApprovalParticipants
    {
        return new ApprovalParticipants(self::WORKFLOW, $correction->id, ['requester' => [$correction->requested_by_user_id]]);
    }
}
