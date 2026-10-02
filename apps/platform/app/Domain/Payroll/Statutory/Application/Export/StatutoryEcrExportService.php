<?php

namespace App\Domain\Payroll\Statutory\Application\Export;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryIdentifierMissingException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRuleVersionNotFoundException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunResultsExpiredException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollPfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6G (ADR 0036 correction addendum §1.11, Section 9) --
 * EPFO ECR data preparation ONLY. This class never submits to any
 * government portal (ADR 0036's export-only filing boundary) -- it
 * returns the formatted row content as a string for a caller to store/
 * download; what happens to that content after this method returns is
 * entirely outside this checkpoint's scope.
 *
 * Row format: exactly the 11 fields specified for this checkpoint --
 * UAN, Member Name, Gross Wages, EPF Wages, EPS Wages, EDLI Wages, EPF
 * Contribution Remitted, EPS Contribution Remitted, EPF-EPS Difference
 * Remitted, NCP Days, Refund of Advances -- joined by the literal
 * delimiter `#~#`, one row per line. This field ORDER and DELIMITER
 * were given explicitly by the governing checkpoint specification;
 * everything else about this class (which stored figure maps to which
 * named field) is this implementation's own mapping choice, NOT
 * independently verified against the live EPFO ECR portal template --
 * a School must verify the actual values before any real submission.
 * Mapping choices, and their DISCLOSED limitations:
 *   - EPF/EPS/EDLI Wages: derived at export time from the already-
 *     persisted `pf_contribution_base` capped again at the currently
 *     active rule version's EPS/EDLI wage ceilings -- a REPORTING
 *     derivation, not a new financial calculation (the authoritative
 *     contribution figures were already computed and frozen by
 *     Checkpoint 9.6D/9.6F).
 *   - EPF Contribution Remitted / EPS Contribution Remitted: the
 *     already-computed `employer_epf`/`employer_eps` figures.
 *   - EPF-EPS Difference Remitted: mapped to `employer_epf` (the
 *     employer's EPF-only remitted amount, i.e. the portion of the
 *     employer's 12% NOT diverted to EPS) -- this checkpoint's
 *     interpretation of that column name; verify against the live
 *     portal template before real use.
 *   - NCP Days: always `0` -- no attendance/loss-of-pay integration is
 *     wired to statutory calculation yet (a disclosed gap, not a
 *     fabricated figure).
 *   - Refund of Advances: always `0.00` -- no advance-refund tracking
 *     exists yet (a disclosed gap).
 *
 * Requires BOTH `payroll.statutory.exports.generate` (may generate
 * statutory exports at all) AND `payroll.statutory.identifiers.view`
 * (the UAN is a real, unmasked Highly Sensitive government identifier
 * -- an ECR row is meaningless without it, so this export cannot be
 * produced by an actor who may not view identifiers). Neither
 * capability is granted to any role by default (ADR 0036).
 */
class StatutoryEcrExportService
{
    use AuthorizesCapability;

    private const DELIMITER = '#~#';

    private const CURRENCY = 'INR';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function generate(PayrollRun $run, User $actor): string
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.exports.generate', $school);
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.identifiers.view', $school);
            // E21.3F: never a silently partial filing after payroll retention.
            if ($run->fresh()?->results_expired_at !== null) {
                throw new StatutoryRunResultsExpiredException($run->id);
            }

            $results = PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                ->with('payrollRunResult.employee')
                ->get();

            $asOf = Carbon::parse($run->period->period_month)->startOfMonth();
            $pfRule = $this->activePfRule($asOf);

            $rows = $results->map(fn (PayrollStatutoryCalculationResult $result) => $this->buildRow($school->id, $result, $pfRule))->all();

            $this->audit->school($school, 'payroll.statutory.export.ecr_generated', actor: $actor, subject: $run, metadata: [
                'rowCount' => count($rows),
            ]);

            return implode("\n", $rows);
        });
    }

    private function buildRow(string $schoolId, PayrollStatutoryCalculationResult $result, PayrollPfRuleVersion $pfRule): string
    {
        $runResult = $result->payrollRunResult;
        $employmentRecordId = $runResult->employment_record_id;

        $uan = $this->decryptIdentifier($schoolId, $employmentRecordId, 'uan');
        if ($uan === null) {
            throw new StatutoryIdentifierMissingException($employmentRecordId, 'uan');
        }

        $contributionBase = Money::of($result->pf_contribution_base ?? '0.00', self::CURRENCY);
        $epsWages = $this->min($contributionBase, Money::of($pfRule->eps_wage_ceiling, self::CURRENCY));
        $edliWages = $this->min($contributionBase, Money::of($pfRule->edli_wage_ceiling, self::CURRENCY));

        return implode(self::DELIMITER, [
            $uan,
            $runResult->employee->full_name,
            $runResult->gross_amount,
            $result->pf_contribution_base ?? '0.00',
            $epsWages->amount(),
            $edliWages->amount(),
            $result->employer_epf ?? '0.00',
            $result->employer_eps ?? '0.00',
            $result->employer_epf ?? '0.00',
            '0',
            '0.00',
        ]);
    }

    private function decryptIdentifier(string $schoolId, string $employmentRecordId, string $type): ?string
    {
        $identifier = EmployeeStatutoryIdentifier::query()
            ->where('school_id', $schoolId)
            ->where('employment_record_id', $employmentRecordId)
            ->where('identifier_type', $type)
            ->first();

        return $identifier?->encrypted_value;
    }

    private function activePfRule(Carbon $asOf): PayrollPfRuleVersion
    {
        $rule = PayrollPfRuleVersion::query()
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($rule === null) {
            throw new StatutoryRuleVersionNotFoundException('PF', $asOf->toDateString());
        }

        return $rule;
    }

    private function min(Money $a, Money $b): Money
    {
        return $a->add($b->negated())->isPositive() ? $b : $a;
    }
}
