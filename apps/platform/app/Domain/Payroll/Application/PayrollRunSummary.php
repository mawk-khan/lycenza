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
 *
 * E21.3F: `resultsExpiredAt` is when payroll retention (E21-D9) first
 * removed a separated Employee's results from this run: from then on the
 * run's per-Employee detail is no longer complete (its ledger posting and
 * totals are unchanged).
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
        public readonly ?Carbon $resultsExpiredAt = null,
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
            resultsExpiredAt: $run->results_expired_at,
        );
    }
}
