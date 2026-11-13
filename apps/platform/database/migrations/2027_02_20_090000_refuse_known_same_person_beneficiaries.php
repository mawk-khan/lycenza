<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-17 (C34-N1, owner decision D-C35-1, 2026-11-13).
 *
 * OD-HR-24 refuses a Payroll decider who is the Employee of a compensation change the run (or
 * a reconciliation obligation) pays first. "Is the Employee" meant: the decider's OWN account
 * has an uncorrected link to that Employee. Reproduced at 463777ce: an account known to be the
 * same person as that Employee's account (two accounts with uncorrected links to one Employee,
 * rule 152) approved the run. The owner rated it MEDIUM.
 *
 * Each of the three self-beneficiary checks now also matches an account known to be the same
 * person as the decider, through `employee_account_link_same_person_accounts()` (C34): approval
 * (`payroll_runs_self_beneficiary_guard`), the posting attestation
 * (`payroll_posting_attestations_self_beneficiary_guard`) and the reconciliation review
 * (`payroll_reversal_reconciliation_reviews_guard`'s subject check). The refusal is unchanged.
 * Evaluated at the decision; no row is read for rewriting or changed.
 *
 * Patched in the LIVE definitions (exactly-once anchors, refusing an unexpected shape), never
 * restated; `down()` restores exactly the clause it changed.
 */
return new class extends Migration
{
    /**
     * signature => [the live clause, its replacement].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const GUARDS = [
        'public.payroll_runs_self_beneficiary_guard()' => [
            'WHERE b.user_id = NEW.approved_by_user_id) THEN',
            'WHERE b.user_id = NEW.approved_by_user_id'
                .' OR b.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.approved_by_user_id) k)) THEN',
        ],
        'public.payroll_posting_attestations_self_beneficiary_guard()' => [
            'WHERE b.user_id = NEW.attested_by_user_id) THEN',
            'WHERE b.user_id = NEW.attested_by_user_id'
                .' OR b.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.attested_by_user_id) k)) THEN',
        ],
        'public.payroll_reversal_reconciliation_reviews_guard()' => [
            'WHERE s.assigned_by_user_id = NEW.reviewed_by_user_id OR s.user_id = NEW.reviewed_by_user_id) THEN',
            'WHERE s.assigned_by_user_id = NEW.reviewed_by_user_id OR s.user_id = NEW.reviewed_by_user_id'
                .' OR s.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.reviewed_by_user_id) k)) THEN',
        ],
    ];

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [$signature])->d;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException("{$signature} no longer has exactly one clause this migration changes.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    public function up(): void
    {
        foreach (self::GUARDS as $signature => [$live, $patched]) {
            $this->patch($signature, $live, $patched);
        }
    }

    public function down(): void
    {
        foreach (self::GUARDS as $signature => [$live, $patched]) {
            $this->patch($signature, $patched, $live);
        }
    }
};
