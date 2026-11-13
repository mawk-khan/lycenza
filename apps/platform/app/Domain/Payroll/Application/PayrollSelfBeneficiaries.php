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
 * 2026-11-06). Identity is `employee_account_links` only: every link not corrected as
 * erroneous, current or ended (OD-HR-25), never a name, email or code. An unlink does
 * not change who a person is. The Employee row survives a rehire, so a new employment
 * changes nothing here.
 *
 * Under the shared compensation lock, which a link change takes exclusively, so
 * no link can change between this answer and the decision's commit.
 *
 * HR.R1-C35 WO-17 (C34-N1, owner decision D-C35-1): "the Employee" includes an account
 * KNOWN to be the same person as one linked to it (rule 152), through the same SQL relation
 * the database guards call (`employee_account_link_same_person_accounts`), in this one query.
 * Unknown is never refused.
 */
final class PayrollSelfBeneficiaries
{
    /** Bound to the School. */
    private const CORRECTED = 'EXISTS (SELECT 1 FROM employee_account_links l WHERE l.school_id = ? AND l.employee_id = b.employee_id AND l.voided_at IS NOT NULL)';

    /** WO-17: the actor, or an account known to be the same person. Bound to the actor, the School, then the actor. */
    private const IS_ACTOR = '(%s = ? OR %s IN (SELECT k.user_id FROM employee_account_link_same_person_accounts(?, ?) k))';

    /** Bound to the School, then the actor. */
    private const CORRECTED_FOR_ACTOR = 'EXISTS (SELECT 1 FROM employee_account_links l WHERE l.school_id = ? AND l.employee_id = b.employee_id AND l.voided_at IS NOT NULL AND l.user_id = ?)';

    public function of(PayrollRun $run, string $calculationId, string $userId): SelfBeneficiaryFinding
    {
        $row = DB::selectOne(
            'SELECT COALESCE(bool_or('.sprintf(self::IS_ACTOR, 'b.user_id', 'b.user_id').'), false) AS conflicted, count(*) FILTER (WHERE b.user_id IS NULL) AS unlinked,
                    count(DISTINCT b.employee_id) FILTER (WHERE '.self::CORRECTED.') AS corrected,
                    COALESCE(bool_or('.self::CORRECTED_FOR_ACTOR.'), false) AS own_corrected
               FROM payroll_run_first_paid_beneficiaries(?, ?, ?) b',
            [$userId, $run->school_id, $userId, $run->school_id, $run->school_id, $userId, $run->school_id, $run->id, $calculationId],
        );

        return new SelfBeneficiaryFinding((bool) $row->conflicted, (int) $row->unlinked, correctedEmployees: (int) $row->corrected, actorHadCorrectedLink: (bool) $row->own_corrected);
    }

    /**
     * For an obligation, the moment it opened AND now (`payroll_reversal_reconciliation_subjects`).
     * The live set alone can empty: an ordinary later approval would turn the
     * affected Employee into an "independent" reviewer of nothing.
     */
    public function ofObligation(string $schoolId, string $reconciliationId, string $userId): SelfBeneficiaryFinding
    {
        $row = DB::selectOne(
            'SELECT COALESCE(bool_or('.sprintf(self::IS_ACTOR, 's.user_id', 's.user_id').'), false) AS conflicted, COALESCE(bool_or(s.assigned_by_user_id = ?), false) AS authored,
                    count(DISTINCT s.employee_id) FILTER (WHERE s.user_id IS NULL) AS unlinked,
                    count(DISTINCT s.employee_id) FILTER (WHERE '.str_replace('b.employee_id', 's.employee_id', self::CORRECTED).') AS corrected,
                    COALESCE(bool_or('.str_replace('b.employee_id', 's.employee_id', self::CORRECTED_FOR_ACTOR).'), false) AS own_corrected
               FROM payroll_reversal_reconciliation_subjects(?, ?) s',
            [$userId, $schoolId, $userId, $userId, $schoolId, $schoolId, $userId, $schoolId, $reconciliationId],
        );

        return new SelfBeneficiaryFinding((bool) $row->conflicted, (int) $row->unlinked, (bool) $row->authored, (int) $row->corrected, (bool) $row->own_corrected);
    }
}
