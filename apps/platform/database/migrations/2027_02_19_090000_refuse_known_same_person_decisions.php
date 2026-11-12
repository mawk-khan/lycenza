<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C34 (WO-16; Identity and Security owner decision HR.R1-C33, 2026-11-12).
 *
 * Two accounts with uncorrected links to one Employee are one person, as far as anything
 * here can know (rule 145; the C8 relation `employee_account_link_correction_same_person`).
 * Reproduced at 69ef41c0: one person's second, known-same account approved a payroll run
 * the first account prepared, because every guard below compared account ids only.
 *
 * - `employee_account_link_same_person_accounts(school, user)` returns the OTHER accounts
 *   known to be the same person as `user`: SECURITY INVOKER, STABLE, schema-qualified,
 *   `pg_temp` last, under the caller's RLS (a guard runs for a row of the School its
 *   context names, so only that School's links are read). Empty means unknown, never
 *   proof of two people.
 * - Five guards refuse a decision by such an account in exactly the role each already
 *   refuses the decider's own account: Payroll approval, posting attestation and
 *   reconciliation review; Leave approval and rejection; separation approval and
 *   rejection. Leave and separation also refuse a decider with an uncorrected link,
 *   current or ended, to the requester's or the separating Employee (an unlink does not
 *   change who a person is).
 * - Evaluated at the decision, from the link history as it then stands. Nothing here reads
 *   or rewrites a completed decision; no row is changed.
 *
 * The guards are patched in their LIVE definitions, never restated (as 2027_02_10_090000
 * does): later migrations pinned their search paths, and a copy of an older body would
 * silently undo that.
 */
return new class extends Migration
{
    /**
     * signature => [the live clause this change follows, what it adds after it].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const GUARDS = [
        'public.payroll_runs_refuse_participant_approval()' => [
            <<<'SQL'
                    RAISE EXCEPTION 'payroll_runs_participant_approval_guard: payroll run % is approved by someone who prepared it', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
            SQL,
            <<<'SQL'

                -- HR.R1-C34 (WO-16): nor by an account known to be the same person as someone who prepared it, or as the
                -- author of a compensation change it pays first (checked for the approver by payroll_runs_approval_evidence_guard,
                -- which runs first and holds the shared compensation lock every link change takes exclusively).
                IF EXISTS (SELECT 1 FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.approved_by_user_id) k
                            WHERE public.payroll_run_has_participant(NEW.school_id, NEW.id, k.user_id)
                               OR (NEW.run_kind = 'regular' AND EXISTS (
                                   SELECT 1 FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.id, NEW.approved_calculation_id) f
                                    WHERE f.assigned_by_user_id = k.user_id))) THEN
                    RAISE EXCEPTION 'payroll_runs_participant_approval_guard: payroll run % is approved by an account known to be the same person as one who prepared it', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
            SQL,
        ],
        'public.payroll_posting_attestations_guard()' => [
            <<<'SQL'
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestor is independent of every maker of the run' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
            <<<'SQL'

                -- HR.R1-C34 (WO-16): nor known to be the same person as the approver or a maker.
                IF EXISTS (SELECT 1 FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.attested_by_user_id) k
                            WHERE k.user_id = v_approver
                               OR public.payroll_run_has_participant(NEW.school_id, NEW.payroll_run_id, k.user_id)
                               OR EXISTS (SELECT 1 FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.payroll_run_id, v_approved_calculation) f
                                           WHERE f.assigned_by_user_id = k.user_id)) THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestor is not known to be the same person as the approver or a maker of the run' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
        ],
        'public.payroll_reversal_reconciliation_reviews_guard()' => [
            <<<'SQL'
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a reviewer is independent of the run, its reversal and its first-paid changes' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
            <<<'SQL'

                -- HR.R1-C34 (WO-16): nor known to be the same person as the approver, the reverser, a maker or an author.
                IF EXISTS (SELECT 1 FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.reviewed_by_user_id) k
                            WHERE k.user_id = v_approver OR k.user_id = v_reverser
                               OR public.payroll_run_has_participant(NEW.school_id, v_run, k.user_id)
                               OR EXISTS (SELECT 1 FROM public.payroll_reversal_reconciliation_subjects(NEW.school_id, NEW.reconciliation_id) s
                                           WHERE s.assigned_by_user_id = k.user_id)) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a reviewer is not known to be the same person as the approver, the reverser, a maker or an author' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
        ],
        'public.leave_decisions_refuse_submitter()' => [
            <<<'SQL'
                    RAISE EXCEPTION 'leave_decisions_participant_guard: leave request % is decided by the person who submitted it', NEW.leave_request_id
                        USING ERRCODE = 'check_violation';
                END IF;
            SQL,
            <<<'SQL'

                -- HR.R1-C34 (WO-16): nor by an account known to be the submitter, or the requester through an uncorrected
                -- link, current or ended. The decider's own CURRENT link to the requester stays leave_decisions_no_self_decision's.
                IF NEW.decision IN ('approved', 'rejected') AND NEW.decider_employee_id IS DISTINCT FROM NEW.requester_employee_id AND EXISTS (
                    SELECT 1 FROM public.leave_requests r
                     WHERE r.id = NEW.leave_request_id AND r.school_id = NEW.school_id
                       AND (r.submitted_by_user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.decided_by_user_id) k)
                            OR EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = NEW.school_id AND l.employee_id = r.employee_id AND l.voided_at IS NULL
                                          AND (l.user_id = NEW.decided_by_user_id
                                               OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.decided_by_user_id) k))))
                ) THEN
                    RAISE EXCEPTION 'leave_decisions_same_person: leave request % is decided by an account known to be its requester or submitter', NEW.leave_request_id
                        USING ERRCODE = 'check_violation';
                END IF;
            SQL,
        ],
        'public.hr_employment_separation_cases_guard()' => [
            <<<'SQL'
                    RAISE EXCEPTION 'nobody administers their own separation (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
            <<<'SQL'

                -- HR.R1-C34 (WO-16): a decision is never taken by an account known to be the recorder, or the separating
                -- Employee through an uncorrected link, current or ended.
                IF NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id AND NEW.decided_by_user_id IS NOT NULL AND (
                    NEW.requested_by_user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.decided_by_user_id) k)
                    OR EXISTS (SELECT 1 FROM public.employment_records er
                                 JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id
                                WHERE er.school_id = NEW.school_id AND er.id = NEW.employment_record_id AND l.voided_at IS NULL
                                  AND (l.user_id = NEW.decided_by_user_id
                                       OR l.user_id IN (SELECT k.user_id FROM public.employee_account_link_same_person_accounts(NEW.school_id, NEW.decided_by_user_id) k)))) THEN
                    RAISE EXCEPTION 'a separation is decided by an account known to be its recorder or the separating person (hr_employment_separation_same_person)' USING ERRCODE = 'check_violation';
                END IF;
            SQL,
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
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.employee_account_link_same_person_accounts(p_school uuid, p_user uuid) RETURNS TABLE (user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT DISTINCT b.user_id
                  FROM public.employee_account_links a
                  JOIN public.employee_account_links b ON b.school_id = a.school_id AND b.employee_id = a.employee_id
                 WHERE a.school_id = p_school AND a.user_id = p_user AND a.voided_at IS NULL
                   AND b.voided_at IS NULL AND b.user_id <> p_user
            $$;

            REVOKE ALL ON FUNCTION public.employee_account_link_same_person_accounts(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_same_person_accounts(uuid, uuid) TO school_os_app;
            SQL);

        foreach (self::GUARDS as $signature => [$live, $added]) {
            $this->patch($signature, $live, $live.$added);
        }
    }

    public function down(): void
    {
        foreach (self::GUARDS as $signature => [$live, $added]) {
            $this->patch($signature, $live.$added, $live);
        }

        DB::unprepared('DROP FUNCTION public.employee_account_link_same_person_accounts(uuid, uuid)');
    }
};
