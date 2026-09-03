<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use Illuminate\Support\Carbon;

/**
 * Phase 9.7 -- the non-sensitive view of a `PayrollRun`, returned by
 * `PayrollRunReadService` (`payroll.runs.view`). `payroll_runs` itself
 * carries no monetary field at all (amounts live on
 * `payroll_run_results`/`_lines`, gated separately by
 * `payroll.compensation.sensitive.view` via `PayrollRunResultReadService`)
 * -- this DTO is therefore a complete, safe mirror of the row, never a
 * raw Eloquent model.
 */
final class PayrollRunSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $payrollPeriodId,
        public readonly string $runKind,
        public readonly ?string $correctsPayrollRunId,
        public readonly string $status,
        public readonly string $preparedByUserId,
        public readonly ?string $approvedByUserId,
        public readonly ?string $postedByUserId,
        public readonly ?Carbon $approvedAt,
        public readonly ?Carbon $postedAt,
    ) {}

    public static function fromModel(PayrollRun $run): self
    {
        return new self(
            id: $run->id,
            payrollPeriodId: $run->payroll_period_id,
            runKind: $run->run_kind,
            correctsPayrollRunId: $run->corrects_payroll_run_id,
            status: $run->status,
            preparedByUserId: $run->prepared_by_user_id,
            approvedByUserId: $run->approved_by_user_id,
            postedByUserId: $run->posted_by_user_id,
            approvedAt: $run->approved_at,
            postedAt: $run->posted_at,
        );
    }
}
