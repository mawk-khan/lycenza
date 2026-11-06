<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C3 (PAY-13, PAY-14).
 *
 * `payroll_run_first_paid_compensation` is the one answer to "which changes
 * does this run pay first": the review packet, `participants()` and
 * `payroll_runs_approval_evidence_guard` all read it, so correcting it here
 * corrects all three. A change is already paid only by a QUALIFYING predecessor:
 * another regular run, approved or posted, whose approved calculation priced
 * the assignment (basis `structure`), and now also:
 *
 *  - NOT REVERSED. `payroll_runs.status` stays `posted` forever (ADR 0034);
 *    reversal is the run's `reversal` posting. Status alone cannot see it, so
 *    a reversed run used to discharge the attribution.
 *  - APPROVED INDEPENDENTLY OF THE AUTHOR. With run A standing, the author may
 *    legitimately approve later run B. If A is then reversed, B must not stand
 *    in for the independent approval the change no longer has.
 *
 * Nothing about the reversed run is touched: its approval, sources and postings
 * remain history. The change is prospective: a decision already taken stands.
 *
 * `trg_payroll_run_postings_reversal_lock` takes the School's compensation lock
 * EXCLUSIVELY for a reversal (the existing `payroll_compensation_lock()`), so
 * an approval or calculation, which hold it SHARED, is ordered wholly before or
 * after the reversal. No run-to-run lock is needed, so no lock cycle exists.
 *
 * PAY-14: `trg_employee_compensation_assignments_identity` freezes what the
 * attribution and pricing rest on. `CompensationService` only ever closes an
 * assignment (`effective_to`), so no application path changes these columns.
 * The schema owner stays exempt, as in `retention_guard_end_record`.
 *
 * Two raw-SQL routes around the rule are closed too (independent review of this
 * change): `trg_payroll_runs_identity` freezes a run's kind, correction target
 * and period, because the approval guard skips freshness and author checks for a
 * non-regular run; `payroll_runs_decided_has_approver` refuses an approved or
 * posted run with no approver, which would otherwise count as independent of
 * every author.
 *
 * Rollback restores the previous definitions and touches no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(self::firstPaid(<<<'SQL'
                          AND os.compensation_assignment_id = s.compensation_assignment_id
                          -- HR.R1-C3: a reversed run no longer stands (its status never changes; ADR 0034).
                          AND NOT EXISTS (SELECT 1 FROM public.payroll_run_postings rp
                                           WHERE rp.school_id = o.school_id AND rp.payroll_run_id = o.id AND rp.posting_kind = 'reversal')
                          -- HR.R1-C3: only an approval independent of the author discharges the attribution.
                          AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id)
            SQL));

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_run_postings_reversal_lock
                BEFORE INSERT ON payroll_run_postings
                FOR EACH ROW WHEN (NEW.posting_kind = 'reversal')
                EXECUTE FUNCTION public.payroll_compensation_lock();

            CREATE FUNCTION public.payroll_compensation_assignment_identity_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF NEW.assigned_by_user_id IS DISTINCT FROM OLD.assigned_by_user_id OR NEW.salary_structure_id IS DISTINCT FROM OLD.salary_structure_id
                   OR NEW.employment_record_id IS DISTINCT FROM OLD.employment_record_id OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                   OR NEW.school_id IS DISTINCT FROM OLD.school_id THEN
                    RAISE EXCEPTION 'employee_compensation_assignments: an assignment''s author, structure, employment and start are fixed (payroll_compensation_assignment_identity_guard)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_compensation_assignments_identity
                BEFORE UPDATE ON employee_compensation_assignments
                FOR EACH ROW EXECUTE FUNCTION public.payroll_compensation_assignment_identity_guard();

            REVOKE ALL ON FUNCTION public.payroll_compensation_assignment_identity_guard() FROM PUBLIC;

            CREATE FUNCTION public.payroll_runs_identity_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF NEW.run_kind IS DISTINCT FROM OLD.run_kind OR NEW.corrects_payroll_run_id IS DISTINCT FROM OLD.corrects_payroll_run_id
                   OR NEW.payroll_period_id IS DISTINCT FROM OLD.payroll_period_id THEN
                    RAISE EXCEPTION 'payroll_runs: a run''s kind, correction target and period are fixed (payroll_runs_identity_guard)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_runs_identity
                BEFORE UPDATE ON payroll_runs
                FOR EACH ROW EXECUTE FUNCTION public.payroll_runs_identity_guard();

            REVOKE ALL ON FUNCTION public.payroll_runs_identity_guard() FROM PUBLIC;

            ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_decided_has_approver
                CHECK (status NOT IN ('approved', 'posted') OR approved_by_user_id IS NOT NULL);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payroll_runs DROP CONSTRAINT payroll_runs_decided_has_approver;
            DROP TRIGGER trg_payroll_runs_identity ON payroll_runs;
            DROP FUNCTION public.payroll_runs_identity_guard();
            DROP TRIGGER trg_employee_compensation_assignments_identity ON employee_compensation_assignments;
            DROP FUNCTION public.payroll_compensation_assignment_identity_guard();
            DROP TRIGGER trg_payroll_run_postings_reversal_lock ON payroll_run_postings;
            SQL);

        DB::unprepared(self::firstPaid(<<<'SQL'
                          AND os.compensation_assignment_id = s.compensation_assignment_id)
            SQL));
    }

    private static function firstPaid(string $qualification): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION public.payroll_run_first_paid_compensation(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid, assigned_by_user_id uuid, basis text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS \$\$
                SELECT s.compensation_assignment_id, s.employment_record_id, a.assigned_by_user_id, s.basis::text
                  FROM public.payroll_run_calculation_sources s
                  JOIN public.employee_compensation_assignments a ON a.id = s.compensation_assignment_id AND a.school_id = s.school_id
                 WHERE s.school_id = p_school AND s.payroll_run_id = p_run AND s.payroll_run_calculation_id = p_calc
                   AND NOT EXISTS (
                       SELECT 1 FROM public.payroll_runs o
                         JOIN public.payroll_run_calculation_sources os
                           ON os.school_id = o.school_id AND os.payroll_run_id = o.id AND os.payroll_run_calculation_id = o.approved_calculation_id
                        WHERE o.school_id = p_school AND o.id <> p_run AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                          -- Paid means PRICED from the assignment: a run whose period a manual override priced never
                          -- had the assignment's own values approved (security review 2026-11-05).
                          AND os.basis = 'structure'
            {$qualification}
            \$\$;
            SQL;
    }
};
