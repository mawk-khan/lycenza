<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\CorrectionTargetAlreadyReversedException;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;

/**
 * HR.R1-C8 (PAY-17): a correction needs a target that stands. A correction of a void
 * original is a delta with no economic basis, so creating, approving or posting one
 * holds the target FOR SHARE, in the caller's transaction and before the compensation
 * lock, which is the order a reversal takes them. A reversal holds that row FOR UPDATE,
 * so each is ordered wholly before or after the other. Database-backed
 * (`trg_payroll_runs_correction_target_standing`).
 */
final class CorrectionTargetGuard
{
    /** @throws CorrectionTargetAlreadyReversedException */
    public function hold(string $schoolId, string $targetRunId): PayrollRun
    {
        $target = PayrollRun::query()->where('school_id', $schoolId)->whereKey($targetRunId)->sharedLock()->firstOrFail();
        if ($this->reversed($schoolId, $targetRunId)) {
            throw new CorrectionTargetAlreadyReversedException($targetRunId);
        }

        return $target;
    }

    public function reversed(string $schoolId, string $targetRunId): bool
    {
        return PayrollRunPosting::query()->where('school_id', $schoolId)->where('payroll_run_id', $targetRunId)->where('posting_kind', 'reversal')->exists();
    }
}
