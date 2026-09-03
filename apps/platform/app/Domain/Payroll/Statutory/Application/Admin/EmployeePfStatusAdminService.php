<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmploymentRecordNotFoundException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6I (Section 1 "Employee PF status") -- administrative
 * read/write for the five independent PF facts. Never derives one
 * from another (the same discipline `EmployeePfStatus`'s own migration
 * docblock establishes) -- every field is written exactly as given,
 * never inferred from another field in the same payload (e.g. a UAN
 * being present never implies higher-wage approval or EPS
 * eligibility).
 *
 * `configure()` takes a row lock via `lockForUpdate()` inside a
 * transaction so two concurrent admin mutations to the SAME
 * EmploymentRecord's PF status serialize cleanly -- the loser's write
 * still fully applies (there is no state-machine transition here to
 * reject against, unlike a PayrollRun's status), but it can never
 * produce a torn/mixed result combining fields from both concurrent
 * payloads (Checkpoint 9.6I's own concurrency proof).
 */
class EmployeePfStatusAdminService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function view(School $school, string $employmentRecordId, User $actor): ?EmployeePfStatus
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return EmployeePfStatus::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)
                ->first();
        });
    }

    /**
     * @param  array{hasExistingPfMembership: bool, hasUan: bool, hasApprovedHigherWageContribution: bool, higherWageApprovalReference: ?string, higherWageApprovalEffectiveFrom: ?string, isEpsEligible: bool, hasHigherPensionStatus: bool}  $data
     */
    public function configure(School $school, string $employmentRecordId, array $data, User $actor): EmployeePfStatus
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $data, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.manage', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return DB::transaction(function () use ($school, $employmentRecordId, $data, $actor) {
                // Row lock (existing row, if any) -- serializes two
                // concurrent configure() calls for the SAME
                // EmploymentRecord so the final state is always
                // exactly one caller's complete payload, never a
                // torn mix of both.
                EmployeePfStatus::query()
                    ->where('school_id', $school->id)
                    ->where('employment_record_id', $employmentRecordId)
                    ->lockForUpdate()
                    ->first();

                $status = EmployeePfStatus::query()->updateOrCreate(
                    ['school_id' => $school->id, 'employment_record_id' => $employmentRecordId],
                    [
                        'has_existing_pf_membership' => $data['hasExistingPfMembership'],
                        'has_uan' => $data['hasUan'],
                        'has_approved_higher_wage_contribution' => $data['hasApprovedHigherWageContribution'],
                        'higher_wage_approval_reference' => $data['higherWageApprovalReference'],
                        'higher_wage_approval_effective_from' => $data['higherWageApprovalEffectiveFrom'],
                        'is_eps_eligible' => $data['isEpsEligible'],
                        'has_higher_pension_status' => $data['hasHigherPensionStatus'],
                    ],
                );

                $this->audit->school($school, 'payroll.statutory.pf_status.configured', actor: $actor, subject: $status, metadata: [
                    'employmentRecordId' => $employmentRecordId,
                ]);

                return $status;
            });
        });
    }

    private function assertEmploymentRecordExists(School $school, string $employmentRecordId): void
    {
        $exists = EmploymentRecord::query()->where('school_id', $school->id)->where('id', $employmentRecordId)->exists();

        if (! $exists) {
            throw new StatutoryEmploymentRecordNotFoundException($employmentRecordId);
        }
    }
}
