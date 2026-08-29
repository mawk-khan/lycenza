<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRunResult;

/**
 * Phase 9.7 -- one row of `PayrollRunResultReadService::listResults()`,
 * never the raw `PayrollRunResult` Eloquent model. Highly Sensitive
 * (`docs/security/DATA-CLASSIFICATION.md`) -- gated as a WHOLE row by
 * `payroll.compensation.sensitive.view`, never a partial field.
 *
 * @param  list<PayrollRunResultLineDetail>  $lines
 */
final class PayrollRunResultDetail
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly string $employeeId,
        public readonly string $grossAmount,
        public readonly string $totalDeductions,
        public readonly string $netAmount,
        public readonly array $lines,
    ) {}

    public static function fromModel(PayrollRunResult $result): self
    {
        return new self(
            employmentRecordId: $result->employment_record_id,
            employeeId: $result->employee_id,
            grossAmount: $result->gross_amount,
            totalDeductions: $result->total_deductions,
            netAmount: $result->net_amount,
            lines: $result->lines->map(fn ($line) => PayrollRunResultLineDetail::fromModel($line))->all(),
        );
    }
}
