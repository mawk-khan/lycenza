<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OD-HR-1 / OD-HR-2 (owner decisions 2026-11-05): a payroll approval is bound
 * to the EXACT calculation the checker reviewed, and a compensation change
 * records who made it.
 *
 * - `payroll_runs.approved_calculation_id`: set once, by the decision. The
 *   composite key (approved_calculation_id, id, school_id) ->
 *   payroll_run_calculations (id, payroll_run_id, school_id) keeps it to a
 *   calculation of THIS run in THIS School.
 *   `payroll_runs_approval_evidence_guard` (every role) refuses:
 *     - a calculated -> approved transition whose calculation is not the
 *       run's LATEST one (a stale review);
 *     - setting or changing it on any other transition (it is history:
 *       an approved run's evidence never changes).
 *   Runs approved before this migration keep NULL: what their checker saw
 *   was never recorded, and nothing is backfilled or certified now.
 * - `employee_compensation_assignments.assigned_by_user_id`: who recorded a
 *   compensation change, so its author joins the run's maker set (OD-HR-2).
 *   NULL for rows recorded before this migration (not recorded, never
 *   guessed). History fails closed: ON DELETE RESTRICT (rule 97).
 *
 * Rollback refuses while any approval evidence or recorded author exists
 * (forward-fix or restore, never a silent loss).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_calculations', function (Blueprint $table) {
            $table->unique(['id', 'payroll_run_id', 'school_id'], 'payroll_run_calculations_identity_unique');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->uuid('approved_calculation_id')->nullable()->after('approved_at');
            $table->index('approved_calculation_id');
            $table->foreign(['approved_calculation_id', 'id', 'school_id'], 'payroll_runs_approved_calculation_fk')
                ->references(['id', 'payroll_run_id', 'school_id'])->on('payroll_run_calculations');
        });

        Schema::table('employee_compensation_assignments', function (Blueprint $table) {
            $table->foreignUuid('assigned_by_user_id')->nullable()->after('effective_to')->constrained('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_runs_approval_evidence_guard() RETURNS trigger
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

            CREATE TRIGGER payroll_runs_approval_evidence_guard
                BEFORE UPDATE OF status, approved_calculation_id ON payroll_runs
                FOR EACH ROW EXECUTE FUNCTION public.payroll_runs_approval_evidence_guard();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('payroll_runs')->whereNotNull('approved_calculation_id')->exists()
            || DB::table('employee_compensation_assignments')->whereNotNull('assigned_by_user_id')->exists()) {
            throw new RuntimeException('Refusing to roll back: payroll approval evidence or compensation authorship is recorded (OD-HR-1/2).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payroll_runs_approval_evidence_guard ON payroll_runs;
            DROP FUNCTION IF EXISTS public.payroll_runs_approval_evidence_guard();
            SQL);

        Schema::table('employee_compensation_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_user_id');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropForeign('payroll_runs_approved_calculation_fk');
            $table->dropIndex(['approved_calculation_id']);
            $table->dropColumn('approved_calculation_id');
        });

        Schema::table('payroll_run_calculations', function (Blueprint $table) {
            $table->dropUnique('payroll_run_calculations_identity_unique');
        });
    }
};
