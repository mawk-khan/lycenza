<?php

namespace App\Domain\Payroll\Application;

/**
 * Checkpoint 9.6J -- the statutory extension to `Payslip`, rendered
 * ONLY from an already-frozen, already-calculated
 * `payroll_statutory_calculation_results` row for this exact
 * `PayrollRunResult` (Checkpoint 9.6F) -- never recalculated from the
 * currently active rule versions at render time, so a historical
 * payslip always shows what was actually withheld/contributed under
 * the rule version in force when it was calculated, even after a
 * later legal change.
 *
 * Identifiers are ALWAYS masked here -- there is no reveal flow on a
 * payslip (unlike the Section 9.6I admin identifier surface); showing
 * an identifier "because it exists" is exactly what ADR 0036's privacy
 * requirements exist to prevent. `tds` is this cycle's deduction only
 * -- never the employee's declared-income/deduction dossier
 * (`employee_tax_profile`), which has no place on a payslip.
 *
 * `esiDisabilityProvisionsEvaluated` is always `false` -- this system
 * collects no disability-status fact anywhere (ADR 0036's ESI
 * disability threshold remains `DEFERRED — ADDITIONAL LEGAL
 * CLARIFICATION REQUIRED`), so it can never determine whether the
 * deferred branch would have applied to a specific Employee. This is
 * a permanent, disclosed limitation, not a per-Employee computed
 * result -- a renderer must show this as a standing notice, never
 * omit it.
 */
final class PayslipStatutorySection
{
    public function __construct(
        public readonly bool $isPfExcludedEmployee,
        public readonly ?string $employeePfMandatory,
        public readonly ?string $employeePfVoluntary,
        public readonly ?string $employerPfTotal,
        public readonly ?string $employerEps,
        public readonly ?string $employerEpf,
        public readonly ?string $employerEdli,
        public readonly bool $esiIsCovered,
        public readonly ?string $employeeEsi,
        public readonly ?string $employerEsi,
        public readonly ?string $professionalTax,
        public readonly bool $lwfCharged,
        public readonly ?string $employeeLwf,
        public readonly ?string $employerLwf,
        public readonly ?string $tdsMonthlyDeduction,
        public readonly ?string $tdsResidualComplianceException,
        public readonly ?string $maskedPan,
        public readonly ?string $maskedUan,
        public readonly ?string $maskedPfMemberId,
        public readonly ?string $maskedEsicIpNumber,
        public readonly bool $esiDisabilityProvisionsEvaluated = false,
    ) {}
}
