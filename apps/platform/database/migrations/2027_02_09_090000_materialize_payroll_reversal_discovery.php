<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C7-R1: `payroll_reversal_affected_runs` went quadratic on a generic plan.
 *
 * HR.R1-C7 put the link check inside the join that finds what a reversed run
 * discharged. A SQL function here is planned without its parameter values, and the
 * planner then estimates one row after that anti-join, moves it ahead of the rest of
 * the join, and scans every assignment for each row. Measured at 10,000 entries:
 * 184 s per call and 560 s for a reversal, all under the School's exclusive
 * compensation lock. An independent run of the old body took 330 ms with no planner
 * statistics and over 60 s with them. So the cost depends on the plan, and one fast
 * measurement proved nothing: the same code once read 184 ms.
 *
 * The join is now materialized before the check, so it cannot be reordered. Only the
 * shape changes: same inputs, same rows. `2027_02_08_090000` is published, so this
 * is a forward migration (ADR 0066 §15.1). `down()` restores that body exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- Each step once, never per row: a run's exception costs a pass over its first-paid set, so asking it
                -- for every source row is quadratic in the run's size, and this runs under the exclusive lock.
                WITH discharged AS MATERIALIZED (
                    -- Only what the reversed run discharged: priced from the assignment, approved independently of its author.
                    SELECT s.payroll_run_id AS reversed_run_id, s.compensation_assignment_id, a.assigned_by_user_id, er.employee_id
                      FROM public.payroll_run_postings rp
                      JOIN public.payroll_runs r ON r.school_id = rp.school_id AND r.id = rp.payroll_run_id
                      JOIN public.payroll_run_calculation_sources s
                        ON s.school_id = r.school_id AND s.payroll_run_id = r.id AND s.payroll_run_calculation_id = r.approved_calculation_id AND s.basis = 'structure'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = s.school_id AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                      JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                     WHERE NOT EXISTS (SELECT 1 FROM public.employee_account_links l
                                        WHERE l.school_id = er.school_id AND l.employee_id = er.employee_id
                                          AND l.user_id = r.approved_by_user_id AND l.voided_at IS NULL)
                       AND rp.school_id = p_school AND rp.id = p_reversal AND rp.posting_kind = 'reversal'
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
            SQL);
    }
};
