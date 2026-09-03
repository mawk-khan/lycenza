<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.10 -- the single, whole read-model returned by
 * `PayslipReadService::render()`. Rendered on demand from the frozen
 * `payroll_run_results`/`_lines` authority -- never persisted, never a
 * second source of financial truth (docs/modules/PAYROLL.md "Payslip
 * rendering"). Highly Sensitive, exactly like `PayrollRunResultDetail`
 * -- gated as a WHOLE object by `payroll.compensation.sensitive.view`,
 * never a partial field.
 *
 * Deliberately excludes: bank details (no bank-account table exists in
 * this module at all), and any salary-structure/revision reference
 * (not stored on the frozen result itself -- re-deriving it live from
 * the current compensation assignment could disagree with what was
 * actually calculated, which would misrepresent the frozen record
 * this DTO exists to represent faithfully).
 *
 * Checkpoint 9.6J -- `$statutory` is populated ONLY when a frozen
 * `payroll_statutory_calculation_results` row exists for this exact
 * `PayrollRunResult` AND the actor also holds `payroll.statutory.view`
 * (a second, narrower authority layered on top of
 * `payroll.compensation.sensitive.view` -- see `PayslipReadService`).
 * `$statutoryDeductionsIncluded` is `true` only in that case -- never
 * inferred, never guessed for a run that was never statutorily
 * calculated (a `draft`/`calculated` run's payslip is already refused
 * entirely by the existing eligibility check; a run calculated before
 * Checkpoint 9.6F, or one an actor lacks `.statutory.view` for, simply
 * shows no statutory section, with the flag `false` so a renderer can
 * show an explicit "statutory figures unavailable" notice instead of
 * silently omitting it -- never labeled "legally compliant" either
 * way, see `PayslipStatutorySection`'s own docblock for why.
 *
 * @param  list<PayslipLine>  $lines
 */
final class Payslip
{
    public function __construct(
        public readonly string $schoolId,
        public readonly string $schoolName,
        public readonly string $payrollRunId,
        public readonly string $runKind,
        public readonly ?string $correctsPayrollRunId,
        public readonly ?string $correctsPayrollPeriodMonth,
        public readonly string $runStatus,
        public readonly bool $isReversed,
        public readonly string $payrollPeriodId,
        public readonly string $periodMonth,
        public readonly ?string $paymentDate,
        public readonly string $employmentRecordId,
        public readonly string $employeeId,
        public readonly ?string $employeeFullName,
        public readonly ?string $employeeNumber,
        public readonly string $grossAmount,
        public readonly string $totalDeductions,
        public readonly string $netAmount,
        public readonly array $lines,
        public readonly bool $statutoryDeductionsIncluded = false,
        public readonly ?PayslipStatutorySection $statutory = null,
    ) {}
}
