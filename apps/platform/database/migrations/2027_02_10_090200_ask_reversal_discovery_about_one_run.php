<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C8: a reversal's cost grew with the square of the runs it affected.
 *
 * `payroll_reversal_reconciliations_guard` proves each obligation names a run the
 * reversal really affected, and did it by rediscovering every affected run, once per
 * obligation. Each discovery costs a pass over the run's first-paid set per candidate,
 * so recording N obligations cost about N squared passes, under the School's exclusive
 * compensation lock. Measured at 10,000 entries: 2 runs 2.2 s, 12 runs 29 s, 24 runs
 * 105 s, 48 runs 550 s.
 *
 * Discovery takes an optional run. With it, the same rule is evaluated for that one
 * candidate. The rule is unchanged and there is no cap, sampling or cache: the
 * two-argument form still finds every run, and calls this one. Only what the guard
 * asks is narrower, which is exactly the question it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid, p_only_run uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- Each step once, never per row: a run's exception costs a pass over its first-paid set, so asking it
                -- for every source row is quadratic in the run's size, and this runs under the exclusive lock.
                -- HR.R1-C7-R1: the join is materialized before the link check. A generic plan estimates one row after that
                -- anti-join, so if it may be reordered into the join, every row scans every assignment: 184 s at 10,000.
                WITH reversed_run_paid AS MATERIALIZED (
                    -- Only what the reversed run discharged: priced from the assignment, approved independently of its author.
                    SELECT s.payroll_run_id AS reversed_run_id, s.compensation_assignment_id, a.assigned_by_user_id, er.employee_id, er.school_id, r.approved_by_user_id
                      FROM public.payroll_run_postings rp
                      JOIN public.payroll_runs r ON r.school_id = rp.school_id AND r.id = rp.payroll_run_id
                      JOIN public.payroll_run_calculation_sources s
                        ON s.school_id = r.school_id AND s.payroll_run_id = r.id AND s.payroll_run_calculation_id = r.approved_calculation_id AND s.basis = 'structure'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = s.school_id AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                      JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                     WHERE rp.school_id = p_school AND rp.id = p_reversal AND rp.posting_kind = 'reversal'
                ), discharged AS MATERIALIZED (
                    SELECT p.reversed_run_id, p.compensation_assignment_id, p.assigned_by_user_id, p.employee_id
                      FROM reversed_run_paid p
                     WHERE NOT EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = p.school_id AND l.employee_id = p.employee_id
                                          AND l.user_id = p.approved_by_user_id AND l.voided_at IS NULL)
                ), candidates AS MATERIALIZED (
                    SELECT DISTINCT o.id, o.status::text AS status
                      FROM discharged d
                      JOIN public.payroll_run_calculation_sources os
                        ON os.school_id = p_school AND os.compensation_assignment_id = d.compensation_assignment_id AND os.payroll_run_id <> d.reversed_run_id
                      JOIN public.payroll_runs o
                        ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
                       AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                       AND (p_only_run IS NULL OR o.id = p_only_run)
                       AND (o.approved_by_user_id = d.assigned_by_user_id
                            OR EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = p_school AND l.employee_id = d.employee_id
                                          AND l.user_id = o.approved_by_user_id AND l.voided_at IS NULL))
                     -- A run already reversed has no live effect left to reconcile.
                     WHERE NOT EXISTS (SELECT 1 FROM public.payroll_run_postings orp
                                        WHERE orp.school_id = o.school_id AND orp.payroll_run_id = o.id AND orp.posting_kind = 'reversal')
                )
                SELECT c.id, c.status
                  FROM candidates c
                 -- The change must really be in the run's exception now, which is what makes this exact (PAY-20).
                 WHERE EXISTS (SELECT 1 FROM public.payroll_run_independence_exception(p_school, c.id) e
                                JOIN discharged d ON d.compensation_assignment_id = e.compensation_assignment_id)
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT * FROM public.payroll_reversal_affected_runs(p_school, p_reversal, NULL::uuid)
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_reversal_reconciliations_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
                v_first_paid uuid[];
                v_implicated uuid[];
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                -- HR.R1-C8: this one run, not every run the reversal touched, and asked once per obligation.
                SELECT f.status INTO v_status FROM public.payroll_reversal_affected_runs(NEW.school_id, NEW.reversal_posting_id, NEW.payroll_run_id) f;
                IF v_status IS DISTINCT FROM 'posted' THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation names a posted run its reversal really affected' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.payroll_runs r WHERE r.school_id = NEW.school_id AND r.id = NEW.payroll_run_id AND r.approved_calculation_id = NEW.payroll_run_calculation_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation names the approved calculation of its run' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.evidence_version IS DISTINCT FROM public.payroll_run_independence_version(NEW.school_id, NEW.payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation binds the current evidence' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.payroll_run_postings p
                                 JOIN public.payroll_runs o ON o.school_id = p.school_id AND o.id = p.payroll_run_id
                                 JOIN public.payroll_periods pp ON pp.school_id = o.school_id AND pp.id = o.payroll_period_id
                                WHERE p.school_id = NEW.school_id AND p.id = NEW.reversal_posting_id
                                  AND p.payroll_run_id = NEW.reversed_payroll_run_id AND p.actor_user_id = NEW.reversed_by_user_id
                                  AND p.created_at = NEW.reversed_at AND pp.period_month = NEW.reversed_period_month) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation copies the facts of its reversal' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(array_agg(f.compensation_assignment_id ORDER BY f.compensation_assignment_id), '{}') INTO v_first_paid
                  FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.payroll_run_id, NEW.payroll_run_calculation_id) f;
                SELECT COALESCE(array_agg(e.compensation_assignment_id ORDER BY e.compensation_assignment_id), '{}') INTO v_implicated
                  FROM public.payroll_run_independence_exception(NEW.school_id, NEW.payroll_run_id) e;
                IF NEW.first_paid_assignments IS DISTINCT FROM v_first_paid OR NEW.implicated_assignments IS DISTINCT FROM v_implicated THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation snapshots the run as it stands' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            REVOKE ALL ON FUNCTION public.payroll_reversal_affected_runs(uuid, uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_reversal_affected_runs(uuid, uuid, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.payroll_reversal_reconciliations_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
                v_first_paid uuid[];
                v_implicated uuid[];
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                SELECT f.status INTO v_status FROM public.payroll_reversal_affected_runs(NEW.school_id, NEW.reversal_posting_id) f
                 WHERE f.payroll_run_id = NEW.payroll_run_id;
                IF v_status IS DISTINCT FROM 'posted' THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation names a posted run its reversal really affected' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.payroll_runs r WHERE r.school_id = NEW.school_id AND r.id = NEW.payroll_run_id AND r.approved_calculation_id = NEW.payroll_run_calculation_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation names the approved calculation of its run' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.evidence_version IS DISTINCT FROM public.payroll_run_independence_version(NEW.school_id, NEW.payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation binds the current evidence' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.payroll_run_postings p
                                 JOIN public.payroll_runs o ON o.school_id = p.school_id AND o.id = p.payroll_run_id
                                 JOIN public.payroll_periods pp ON pp.school_id = o.school_id AND pp.id = o.payroll_period_id
                                WHERE p.school_id = NEW.school_id AND p.id = NEW.reversal_posting_id
                                  AND p.payroll_run_id = NEW.reversed_payroll_run_id AND p.actor_user_id = NEW.reversed_by_user_id
                                  AND p.created_at = NEW.reversed_at AND pp.period_month = NEW.reversed_period_month) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation copies the facts of its reversal' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(array_agg(f.compensation_assignment_id ORDER BY f.compensation_assignment_id), '{}') INTO v_first_paid
                  FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.payroll_run_id, NEW.payroll_run_calculation_id) f;
                SELECT COALESCE(array_agg(e.compensation_assignment_id ORDER BY e.compensation_assignment_id), '{}') INTO v_implicated
                  FROM public.payroll_run_independence_exception(NEW.school_id, NEW.payroll_run_id) e;
                IF NEW.first_paid_assignments IS DISTINCT FROM v_first_paid OR NEW.implicated_assignments IS DISTINCT FROM v_implicated THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliations_guard: an obligation snapshots the run as it stands' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- Each step once, never per row: a run's exception costs a pass over its first-paid set, so asking it
                -- for every source row is quadratic in the run's size, and this runs under the exclusive lock.
                -- HR.R1-C7-R1: the join is materialized before the link check. A generic plan estimates one row after that
                -- anti-join, so if it may be reordered into the join, every row scans every assignment: 184 s at 10,000.
                WITH reversed_run_paid AS MATERIALIZED (
                    -- Only what the reversed run discharged: priced from the assignment, approved independently of its author.
                    SELECT s.payroll_run_id AS reversed_run_id, s.compensation_assignment_id, a.assigned_by_user_id, er.employee_id, er.school_id, r.approved_by_user_id
                      FROM public.payroll_run_postings rp
                      JOIN public.payroll_runs r ON r.school_id = rp.school_id AND r.id = rp.payroll_run_id
                      JOIN public.payroll_run_calculation_sources s
                        ON s.school_id = r.school_id AND s.payroll_run_id = r.id AND s.payroll_run_calculation_id = r.approved_calculation_id AND s.basis = 'structure'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = s.school_id AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                      JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                     WHERE rp.school_id = p_school AND rp.id = p_reversal AND rp.posting_kind = 'reversal'
                ), discharged AS MATERIALIZED (
                    SELECT p.reversed_run_id, p.compensation_assignment_id, p.assigned_by_user_id, p.employee_id
                      FROM reversed_run_paid p
                     WHERE NOT EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = p.school_id AND l.employee_id = p.employee_id
                                          AND l.user_id = p.approved_by_user_id AND l.voided_at IS NULL)
                ), candidates AS MATERIALIZED (
                    SELECT DISTINCT o.id, o.status::text AS status
                      FROM discharged d
                      JOIN public.payroll_run_calculation_sources os
                        ON os.school_id = p_school AND os.compensation_assignment_id = d.compensation_assignment_id AND os.payroll_run_id <> d.reversed_run_id
                      JOIN public.payroll_runs o
                        ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
                       AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                       AND (o.approved_by_user_id = d.assigned_by_user_id
                            OR EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = p_school AND l.employee_id = d.employee_id
                                          AND l.user_id = o.approved_by_user_id AND l.voided_at IS NULL))
                     -- A run already reversed has no live effect left to reconcile.
                     WHERE NOT EXISTS (SELECT 1 FROM public.payroll_run_postings orp
                                        WHERE orp.school_id = o.school_id AND orp.payroll_run_id = o.id AND orp.posting_kind = 'reversal')
                )
                SELECT c.id, c.status
                  FROM candidates c
                 -- The change must really be in the run's exception now, which is what makes this exact (PAY-20).
                 WHERE EXISTS (SELECT 1 FROM public.payroll_run_independence_exception(p_school, c.id) e
                                JOIN discharged d ON d.compensation_assignment_id = e.compensation_assignment_id)
            $$;

            DROP FUNCTION public.payroll_reversal_affected_runs(uuid, uuid, uuid);
            SQL);
    }
};
