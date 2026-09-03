<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this class's body against the
 * Checkpoint 9.6B golden fixtures (`Tests\Unit\Payroll\Statutory\PfCalculationServiceTest`).
 * Pure and stateless -- no database access, no Eloquent model, no
 * capability check -- exactly like `PayrollCalculationEngine`'s own
 * existing precedent (Checkpoint 9.3). The DB-backed rule-version
 * resolution and EmploymentRecord-fact lookup that feed this class
 * its `PfRuleVersion`/`PfEmployeeStatutoryFacts` arguments live one
 * layer up, in an Application-layer service Checkpoint 9.6D also
 * adds.
 */
class PfCalculationService
{
    /**
     * ADR 0035 correction addendum §1.1/§1.2 -- the algorithm, in
     * order:
     *   1. uncappedStatutoryWage = coreWage + max(0, testedRemuneration - 0.5 * (coreWage + testedRemuneration))
     *   2. isExcludedEmployee = uncappedStatutoryWage > membershipWageCeiling
     *      AND NOT (hasExistingPfMembership OR hasApprovedHigherWageContribution)
     *   3. if isExcludedEmployee: every contribution is zero, full stop.
     *   4. contributionBase = hasApprovedHigherWageContribution
     *        ? uncappedStatutoryWage
     *        : min(uncappedStatutoryWage, membershipWageCeiling)
     *   5. employeeMandatoryContribution = contributionBase * employeeContributionRate (whole INR, half-up)
     *   6. employerTotalContribution = contributionBase * employerContributionRate (whole INR, half-up)
     *   7. employerEpsContribution = isEpsEligible
     *        ? min(contributionBase, epsWageCeiling) * epsRate (whole INR, half-up)
     *        : 0
     *   8. employerEpfContribution = employerTotalContribution - employerEpsContribution
     *   9. edliContribution = min(contributionBase, edliWageCeiling) * edliRate (whole INR, half-up)
     *   10. adminCharge = max(min(contributionBase, adminChargeWageCeiling) * adminChargeRate, adminChargeMinimum) (whole INR, half-up)
     *   11. employeeVoluntaryContribution = the input's voluntary amount, verbatim -- never affects employer contributions.
     */
    public function calculate(PfCalculationInput $input, PfRuleVersion $rule): PfCalculationResult
    {
        $currency = $input->coreWage()->currency();
        $zero = Money::of('0.00', $currency);

        $coreWage = $input->coreWage();
        $testedRemuneration = $input->testedRemuneration();
        $remuneration = $coreWage->add($testedRemuneration);
        $halfRemuneration = $remuneration->multiplyByRate('0.5', 2);
        $excess = $testedRemuneration->add($halfRemuneration->negated());
        $addBack = $excess->isPositive() ? $excess : $zero;
        $uncappedStatutoryWage = $coreWage->add($addBack);

        $facts = $input->facts;
        $isExcludedEmployee = $this->exceeds($uncappedStatutoryWage, Money::of($rule->membershipWageCeiling, $currency))
            && ! ($facts->hasExistingPfMembership || $facts->hasApprovedHigherWageContribution);

        if ($isExcludedEmployee) {
            return new PfCalculationResult(
                uncappedStatutoryWage: $uncappedStatutoryWage,
                isExcludedEmployee: true,
                contributionBase: $zero,
                employeeMandatoryContribution: $zero,
                employeeVoluntaryContribution: $input->voluntaryEmployeeContribution ?? $zero,
                employerTotalContribution: $zero,
                employerEpsContribution: $zero,
                employerEpfContribution: $zero,
                edliContribution: $zero,
                adminCharge: $zero,
            );
        }

        $membershipWageCeiling = Money::of($rule->membershipWageCeiling, $currency);
        $contributionBase = $facts->hasApprovedHigherWageContribution
            ? $uncappedStatutoryWage
            : $this->min($uncappedStatutoryWage, $membershipWageCeiling);

        $employeeMandatory = $this->wholeRupee($contributionBase, $rule->employeeContributionRate);
        $employerTotal = $this->wholeRupee($contributionBase, $rule->employerContributionRate);

        $epsWageCeiling = Money::of($rule->epsWageCeiling, $currency);
        $epsBase = $this->min($contributionBase, $epsWageCeiling);
        $employerEps = $facts->isEpsEligible ? $this->wholeRupee($epsBase, $rule->epsRate) : $zero;
        $employerEpf = $employerTotal->add($employerEps->negated());

        $edliWageCeiling = Money::of($rule->edliWageCeiling, $currency);
        $edliBase = $this->min($contributionBase, $edliWageCeiling);
        $edli = $this->wholeRupee($edliBase, $rule->edliRate);

        $adminChargeMinimum = Money::of($rule->adminChargeMinimum, $currency);
        $adminChargeRaw = $this->wholeRupee($edliBase, $rule->adminChargeRate);
        $adminCharge = $this->exceeds($adminChargeRaw, $adminChargeMinimum) ? $adminChargeRaw : $adminChargeMinimum;

        return new PfCalculationResult(
            uncappedStatutoryWage: $uncappedStatutoryWage,
            isExcludedEmployee: false,
            contributionBase: $contributionBase,
            employeeMandatoryContribution: $employeeMandatory,
            employeeVoluntaryContribution: $input->voluntaryEmployeeContribution ?? $zero,
            employerTotalContribution: $employerTotal,
            employerEpsContribution: $employerEps,
            employerEpfContribution: $employerEpf,
            edliContribution: $edli,
            adminCharge: $adminCharge,
        );
    }

    private function exceeds(Money $a, Money $b): bool
    {
        return $a->add($b->negated())->isPositive();
    }

    private function min(Money $a, Money $b): Money
    {
        return $this->exceeds($a, $b) ? $b : $a;
    }

    /**
     * Statutory "whole INR" rounding: rounds the RESULT to zero
     * decimal places (half-up, `Money::multiplyByRate()`'s own
     * behavior) -- the actual legally-significant rounding decision --
     * then re-expresses that already-whole amount at the DTO's usual
     * 2-decimal-place format (an exact re-scale, never a second
     * rounding decision, since the value is already an integer).
     */
    private function wholeRupee(Money $base, string $rate): Money
    {
        return $base->multiplyByRate($rate, 0)->multiplyByRate('1.00', 2);
    }
}
