<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C6 (PAY-19, OD-HR-24): the one answer to "is this actor the Employee of a
 * compensation change this run pays first". Approval, the C4 attestation and the
 * C6 review all ask here, and the database guards read the same SQL function.
 *
 * An ordinary salary recipient is not a beneficiary: only a change the run pays
 * FIRST counts, whoever authored it and whatever it did to the amount. An
 * Employee's first assignment counts too, so a School's first payroll and a
 * School-wide revision need a decider not paid by them (owner decision
 * 2026-11-06). Identity is `employees.user_id` only: never a name, email or code.
 * The Employee row survives a rehire, so a new employment changes nothing here.
 *
 * Under the shared compensation lock, which a link change takes exclusively, so
 * no link can change between this answer and the decision's commit.
 */
final class PayrollSelfBeneficiaries
{
    public function of(PayrollRun $run, string $calculationId, string $userId): SelfBeneficiaryFinding
    {
        $row = DB::selectOne(
            'SELECT COALESCE(bool_or(b.user_id = ?), false) AS conflicted, count(*) FILTER (WHERE b.user_id IS NULL) AS unlinked
               FROM payroll_run_first_paid_beneficiaries(?, ?, ?) b',
            [$userId, $run->school_id, $run->id, $calculationId],
        );

        return new SelfBeneficiaryFinding((bool) $row->conflicted, (int) $row->unlinked);
    }

    /**
     * For an obligation, the moment it opened AND now (`payroll_reversal_reconciliation_subjects`).
     * The live set alone can empty: an ordinary later approval would turn the
     * affected Employee into an "independent" reviewer of nothing.
     */
    public function ofObligation(string $schoolId, string $reconciliationId, string $userId): SelfBeneficiaryFinding
    {
        $row = DB::selectOne(
            'SELECT COALESCE(bool_or(s.user_id = ?), false) AS conflicted, COALESCE(bool_or(s.assigned_by_user_id = ?), false) AS authored,
                    count(DISTINCT s.employee_id) FILTER (WHERE s.user_id IS NULL) AS unlinked
               FROM payroll_reversal_reconciliation_subjects(?, ?) s',
            [$userId, $userId, $schoolId, $reconciliationId],
        );

        return new SelfBeneficiaryFinding((bool) $row->conflicted, (int) $row->unlinked, (bool) $row->authored);
    }
}
