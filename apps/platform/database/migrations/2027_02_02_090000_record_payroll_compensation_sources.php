<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C1 (HR.R1-0 findings PAY-2, PAY-3, PAY-4): a payroll calculation
 * records the compensation it rests on, an approval needs a calculation that
 * is still current for its period's compensation, and the author of a
 * compensation change never approves the run that FIRST PAYS it.
 *
 * - `payroll_run_calculation_sources` (append-only, forced RLS): for every
 *   calculation of a REGULAR run, every compensation assignment overlapping
 *   the run's period of every employment it resolved into a result (for a
 *   complete calculation, every such assignment), with the effective range it had when the calculation
 *   read it and the basis (`structure`: the whole period came from that
 *   assignment; `period_override`: the period changed, so a manual override
 *   priced it). A correction run is priced by its deltas and has no sources.
 * - `payroll_run_calculations.compensation_sources_recorded`: TRUE for every
 *   calculation made from now on. Earlier calculations recorded nothing and
 *   are never backfilled: such a run is approved only after a recalculation.
 * - `payroll_run_results.payroll_run_calculation_id` (deferred composite key
 *   to a calculation of the same run and School): the results ARE the output
 *   of one calculation; amounts and identity are never rewritten afterwards
 *   (a recalculation replaces them, with a new calculation).
 *
 * One resolver, in SQL, used by the approval guard below AND by the PHP
 * services (PayrollCompensationProvenance):
 * - `payroll_calculation_compensation_fresh(school, run, calc)`: the
 *   compensation overlapping the period now is exactly what the calculation
 *   recorded (ids and effective ranges, each clipped to the period).
 * - `payroll_run_first_paid_compensation(school, run, calc)`: the sources of
 *   the calculation that no OTHER approved or posted regular run's approved
 *   calculation PRICED from the assignment (basis `structure`; a period a
 *   manual override priced never approved the assignment's own values) --
 *   the changes this run pays first, with their author.
 * - `trg_payroll_run_calculation_sources_guard`: a source row is written only
 *   for the latest calculation of a regular run still being prepared, and
 *   only as the assignment is now -- provenance cannot be forged onto an
 *   approved run.
 *
 * `payroll_runs_approval_evidence_guard` (every role) now also refuses a
 * calculated -> approved transition whose calculation recorded no sources,
 * whose results are not exactly that calculation's, whose results do not
 * reconcile with their lines, whose period's compensation changed after the
 * calculation, or whose approver authored a change the run first pays.
 *
 * Serialization: a compensation write takes the School's
 * `payroll.compensation:{school}` advisory lock EXCLUSIVELY (this trigger,
 * and CompensationService before its row locks); calculation and approval
 * take it SHARED (PayrollRunService, and the guard). A freshness check can
 * therefore never be overtaken by a concurrent compensation write.
 *
 * Rollback refuses while any recorded provenance exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_calculations', function (Blueprint $table) {
            $table->boolean('compensation_sources_recorded')->default(false);
        });

        Schema::create('payroll_run_calculation_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('payroll_run_calculation_id');
            $table->uuid('compensation_assignment_id');
            $table->uuid('employment_record_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('basis', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payroll_run_calculation_id', 'compensation_assignment_id'], 'payroll_run_calculation_sources_unique');
            $table->index(['school_id', 'compensation_assignment_id'], 'payroll_run_calculation_sources_assignment_idx');

            // The provenance leaves with its calculation (and so with its run).
            $table->foreign(['payroll_run_calculation_id', 'payroll_run_id', 'school_id'], 'payroll_run_calculation_sources_calculation_fk')
                ->references(['id', 'payroll_run_id', 'school_id'])->on('payroll_run_calculations')->cascadeOnDelete();
            // Only the privileged evidence purge deletes an assignment (8 y after final separation); its provenance goes with it.
            $table->foreign(['compensation_assignment_id', 'school_id'], 'payroll_run_calculation_sources_assignment_fk')
                ->references(['id', 'school_id'])->on('employee_compensation_assignments')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE payroll_run_calculation_sources ADD CONSTRAINT payroll_run_calculation_sources_basis_check CHECK (basis IN ('structure', 'period_override'))");
        DB::statement('ALTER TABLE payroll_run_calculation_sources ADD CONSTRAINT payroll_run_calculation_sources_range_check CHECK (effective_to IS NULL OR effective_from <= effective_to)');

        Schema::table('payroll_run_results', function (Blueprint $table) {
            $table->uuid('payroll_run_calculation_id')->nullable();
        });
        // Results are written before their calculation row (same transaction): the key is checked at commit.
        DB::statement('ALTER TABLE payroll_run_results ADD CONSTRAINT payroll_run_results_calculation_fk FOREIGN KEY (payroll_run_calculation_id, payroll_run_id, school_id) REFERENCES payroll_run_calculations (id, payroll_run_id, school_id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED');
        DB::statement('CREATE INDEX payroll_run_results_calculation_idx ON payroll_run_results (payroll_run_calculation_id)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_compensation_lock() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                -- Exclusive: no calculation or approval of this School reads compensation while it changes.
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_compensation_assignments_payroll_lock
                BEFORE INSERT OR UPDATE ON employee_compensation_assignments
                FOR EACH ROW EXECUTE FUNCTION public.payroll_compensation_lock();

            CREATE FUNCTION public.payroll_run_results_reject_rewrite() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.gross_amount IS DISTINCT FROM OLD.gross_amount OR NEW.total_deductions IS DISTINCT FROM OLD.total_deductions
                   OR NEW.net_amount IS DISTINCT FROM OLD.net_amount OR NEW.payroll_run_calculation_id IS DISTINCT FROM OLD.payroll_run_calculation_id
                   OR NEW.payroll_run_id IS DISTINCT FROM OLD.payroll_run_id OR NEW.employment_record_id IS DISTINCT FROM OLD.employment_record_id
                   OR NEW.employee_id IS DISTINCT FROM OLD.employee_id OR NEW.school_id IS DISTINCT FROM OLD.school_id THEN
                    RAISE EXCEPTION 'payroll_run_results: a calculated result is never rewritten -- recalculate the run instead'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_run_results_reject_rewrite
                BEFORE UPDATE ON payroll_run_results
                FOR EACH ROW EXECUTE FUNCTION public.payroll_run_results_reject_rewrite();

            CREATE FUNCTION public.payroll_run_result_lines_reject_rewrite() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.amount IS DISTINCT FROM OLD.amount OR NEW.effect IS DISTINCT FROM OLD.effect
                   OR NEW.salary_component_id IS DISTINCT FROM OLD.salary_component_id OR NEW.payroll_run_result_id IS DISTINCT FROM OLD.payroll_run_result_id
                   OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.school_id IS DISTINCT FROM OLD.school_id THEN
                    RAISE EXCEPTION 'payroll_run_result_lines: a calculated line is never rewritten -- recalculate the run instead'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_run_result_lines_reject_rewrite
                BEFORE UPDATE ON payroll_run_result_lines
                FOR EACH ROW EXECUTE FUNCTION public.payroll_run_result_lines_reject_rewrite();

            -- Provenance is written only for the LATEST calculation of a regular run still being prepared, and only as the
            -- assignment is now: no row can be attached to an approved run's calculation (security review 2026-11-05).
            CREATE FUNCTION public.payroll_run_calculation_sources_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
                v_kind text;
                v_latest uuid;
                v_assignment record;
            BEGIN
                SELECT r.status, r.run_kind INTO v_status, v_kind FROM public.payroll_runs r
                 WHERE r.id = NEW.payroll_run_id AND r.school_id = NEW.school_id FOR SHARE;
                IF v_kind IS DISTINCT FROM 'regular' OR (v_status IS DISTINCT FROM 'draft' AND v_status IS DISTINCT FROM 'calculated') THEN
                    RAISE EXCEPTION 'payroll_run_calculation_sources: run % is not a regular run being prepared -- its provenance is final', NEW.payroll_run_id
                        USING ERRCODE = 'check_violation';
                END IF;
                SELECT c.id INTO v_latest FROM public.payroll_run_calculations c
                 WHERE c.school_id = NEW.school_id AND c.payroll_run_id = NEW.payroll_run_id ORDER BY c.ordinal DESC LIMIT 1;
                IF v_latest IS DISTINCT FROM NEW.payroll_run_calculation_id THEN
                    RAISE EXCEPTION 'payroll_run_calculation_sources: only the run''s latest calculation records provenance'
                        USING ERRCODE = 'check_violation';
                END IF;
                SELECT a.employment_record_id, a.effective_from, a.effective_to INTO v_assignment FROM public.employee_compensation_assignments a
                 WHERE a.id = NEW.compensation_assignment_id AND a.school_id = NEW.school_id;
                IF NOT FOUND OR v_assignment.employment_record_id IS DISTINCT FROM NEW.employment_record_id
                   OR v_assignment.effective_from IS DISTINCT FROM NEW.effective_from OR v_assignment.effective_to IS DISTINCT FROM NEW.effective_to THEN
                    RAISE EXCEPTION 'payroll_run_calculation_sources: provenance records the assignment exactly as it is'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_run_calculation_sources_guard
                BEFORE INSERT ON payroll_run_calculation_sources
                FOR EACH ROW EXECUTE FUNCTION public.payroll_run_calculation_sources_guard();

            -- The period's compensation now is exactly what the calculation recorded (ids and effective ranges).
            CREATE FUNCTION public.payroll_calculation_compensation_fresh(p_school uuid, p_run uuid, p_calc uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                WITH period AS (
                    SELECT p.starts_on, p.ends_on FROM public.payroll_runs r
                      JOIN public.payroll_periods p ON p.id = r.payroll_period_id AND p.school_id = r.school_id
                     WHERE r.school_id = p_school AND r.id = p_run
                ), current_set AS (
                    -- Each range clipped to the period: only what the period pays decides (a successor recorded for a
                    -- LATER period closes the current row after this period, which changes nothing this run pays).
                    SELECT a.id, GREATEST(a.effective_from, period.starts_on), LEAST(COALESCE(a.effective_to, period.ends_on), period.ends_on)
                      FROM public.employee_compensation_assignments a, period
                     WHERE a.school_id = p_school AND a.effective_from <= period.ends_on AND (a.effective_to IS NULL OR a.effective_to >= period.starts_on)
                ), recorded AS (
                    SELECT s.compensation_assignment_id, GREATEST(s.effective_from, period.starts_on), LEAST(COALESCE(s.effective_to, period.ends_on), period.ends_on)
                      FROM public.payroll_run_calculation_sources s, period
                     WHERE s.school_id = p_school AND s.payroll_run_id = p_run AND s.payroll_run_calculation_id = p_calc
                )
                SELECT EXISTS (SELECT 1 FROM period)
                   AND NOT EXISTS (SELECT * FROM current_set EXCEPT SELECT * FROM recorded)
                   AND NOT EXISTS (SELECT * FROM recorded EXCEPT SELECT * FROM current_set)
            $$;

            -- The changes this run pays FIRST: its calculation's sources that no other approved or posted regular run paid.
            CREATE FUNCTION public.payroll_run_first_paid_compensation(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid, assigned_by_user_id uuid, basis text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
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
                          AND os.compensation_assignment_id = s.compensation_assignment_id)
            $$;

            -- Every result's gross and deductions equal its own lines (earnings and deductions, signed by effect).
            CREATE FUNCTION public.payroll_run_results_reconcile(p_school uuid, p_run uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT NOT EXISTS (
                    SELECT 1 FROM public.payroll_run_results r
                     WHERE r.school_id = p_school AND r.payroll_run_id = p_run
                       AND (r.gross_amount - r.total_deductions <> r.net_amount
                            OR r.gross_amount <> COALESCE((SELECT sum(CASE WHEN l.effect = 'decrease' THEN -l.amount ELSE l.amount END)
                                  FROM public.payroll_run_result_lines l JOIN public.salary_components c ON c.id = l.salary_component_id AND c.school_id = l.school_id
                                 WHERE l.school_id = r.school_id AND l.payroll_run_result_id = r.id AND c.type = 'earning'), 0)
                            OR r.total_deductions <> COALESCE((SELECT sum(CASE WHEN l.effect = 'decrease' THEN -l.amount ELSE l.amount END)
                                  FROM public.payroll_run_result_lines l JOIN public.salary_components c ON c.id = l.salary_component_id AND c.school_id = l.school_id
                                 WHERE l.school_id = r.school_id AND l.payroll_run_result_id = r.id AND c.type = 'deduction'), 0)))
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_runs_approval_evidence_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_latest uuid;
                v_recorded boolean;
            BEGIN
                IF OLD.approved_calculation_id IS NOT NULL AND NEW.approved_calculation_id IS DISTINCT FROM OLD.approved_calculation_id THEN
                    RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % approval evidence is final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'calculated' AND NEW.status = 'approved' THEN
                    SELECT c.id, c.compensation_sources_recorded INTO v_latest, v_recorded FROM public.payroll_run_calculations c
                     WHERE c.school_id = NEW.school_id AND c.payroll_run_id = NEW.id
                     ORDER BY c.ordinal DESC LIMIT 1;
                    IF NEW.approved_calculation_id IS NULL OR NEW.approved_calculation_id IS DISTINCT FROM v_latest THEN
                        RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % must be approved against its latest calculation', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    -- HR.R1-C1: no compensation write of this School runs while the evidence is checked.
                    PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                    IF NOT COALESCE(v_recorded, false) THEN
                        RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % was calculated before compensation provenance was recorded -- recalculate it', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM public.payroll_run_results r WHERE r.school_id = NEW.school_id AND r.payroll_run_id = NEW.id)
                       OR EXISTS (SELECT 1 FROM public.payroll_run_results r WHERE r.school_id = NEW.school_id AND r.payroll_run_id = NEW.id
                                     AND r.payroll_run_calculation_id IS DISTINCT FROM NEW.approved_calculation_id) THEN
                        RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % results are not those of the approved calculation', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT public.payroll_run_results_reconcile(NEW.school_id, NEW.id) THEN
                        RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % results do not reconcile with their lines', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.run_kind = 'regular' THEN
                        IF NOT public.payroll_calculation_compensation_fresh(NEW.school_id, NEW.id, NEW.approved_calculation_id) THEN
                            RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % period compensation changed after its calculation', NEW.id
                                USING ERRCODE = 'check_violation';
                        END IF;
                        IF EXISTS (SELECT 1 FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.id, NEW.approved_calculation_id) f
                                    WHERE f.assigned_by_user_id = NEW.approved_by_user_id) THEN
                            RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % is approved by the author of a compensation change it first pays', NEW.id
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                ELSIF NEW.approved_calculation_id IS DISTINCT FROM OLD.approved_calculation_id THEN
                    RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: approval evidence is recorded only by the approval of payroll run %', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            REVOKE ALL ON FUNCTION public.payroll_compensation_lock() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_calculation_sources_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_results_reject_rewrite() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_result_lines_reject_rewrite() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_calculation_compensation_fresh(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_first_paid_compensation(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_results_reconcile(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_calculation_compensation_fresh(uuid, uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_run_first_paid_compensation(uuid, uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_run_results_reconcile(uuid, uuid) TO school_os_app;
            SQL);

        TenantRls::enable('payroll_run_calculation_sources');
        TenantRls::makeAppendOnly('payroll_run_calculation_sources');
    }

    public function down(): void
    {
        if (DB::table('payroll_run_calculation_sources')->exists()
            || DB::table('payroll_run_calculations')->where('compensation_sources_recorded', true)->exists()
            || DB::table('payroll_run_results')->whereNotNull('payroll_run_calculation_id')->exists()) {
            throw new RuntimeException('Refusing to roll back: payroll compensation provenance is recorded (HR.R1-C1).');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.payroll_runs_approval_evidence_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_latest uuid;
            BEGIN
                IF OLD.approved_calculation_id IS NOT NULL AND NEW.approved_calculation_id IS DISTINCT FROM OLD.approved_calculation_id THEN
                    RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % approval evidence is final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status = 'calculated' AND NEW.status = 'approved' THEN
                    SELECT c.id INTO v_latest FROM public.payroll_run_calculations c
                     WHERE c.school_id = NEW.school_id AND c.payroll_run_id = NEW.id
                     ORDER BY c.ordinal DESC LIMIT 1;
                    IF NEW.approved_calculation_id IS NULL OR NEW.approved_calculation_id IS DISTINCT FROM v_latest THEN
                        RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: payroll run % must be approved against its latest calculation', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.approved_calculation_id IS DISTINCT FROM OLD.approved_calculation_id THEN
                    RAISE EXCEPTION 'payroll_runs_approval_evidence_guard: approval evidence is recorded only by the approval of payroll run %', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP FUNCTION IF EXISTS public.payroll_run_results_reconcile(uuid, uuid);
            DROP FUNCTION IF EXISTS public.payroll_run_first_paid_compensation(uuid, uuid, uuid);
            DROP FUNCTION IF EXISTS public.payroll_calculation_compensation_fresh(uuid, uuid, uuid);
            DROP TRIGGER IF EXISTS trg_payroll_run_calculation_sources_guard ON payroll_run_calculation_sources;
            DROP FUNCTION IF EXISTS public.payroll_run_calculation_sources_guard();
            DROP TRIGGER IF EXISTS trg_payroll_run_result_lines_reject_rewrite ON payroll_run_result_lines;
            DROP FUNCTION IF EXISTS public.payroll_run_result_lines_reject_rewrite();
            DROP TRIGGER IF EXISTS trg_payroll_run_results_reject_rewrite ON payroll_run_results;
            DROP FUNCTION IF EXISTS public.payroll_run_results_reject_rewrite();
            DROP TRIGGER IF EXISTS trg_employee_compensation_assignments_payroll_lock ON employee_compensation_assignments;
            DROP FUNCTION IF EXISTS public.payroll_compensation_lock();
            SQL);

        DB::statement('DROP INDEX IF EXISTS payroll_run_results_calculation_idx');
        DB::statement('ALTER TABLE payroll_run_results DROP CONSTRAINT IF EXISTS payroll_run_results_calculation_fk');
        Schema::table('payroll_run_results', function (Blueprint $table) {
            $table->dropColumn('payroll_run_calculation_id');
        });

        TenantRls::disable('payroll_run_calculation_sources');
        Schema::dropIfExists('payroll_run_calculation_sources');

        Schema::table('payroll_run_calculations', function (Blueprint $table) {
            $table->dropColumn('compensation_sources_recorded');
        });
    }
};
