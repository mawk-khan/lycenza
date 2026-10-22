<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.8B (ADR 0076 §60, closes ORG.8 G-2): payroll maker/checker covers every
 * human who materially prepared the run, not only its creator.
 *
 * Who prepares a run, durably:
 *   - its creator: `payroll_runs.prepared_by_user_id` (unchanged);
 *   - every author of a manual override or correction delta:
 *     `payroll_adjustments.actor_user_id` (append-only already);
 *   - every User who calculated it: NEW `payroll_run_calculations`. A
 *     calculation replaces the whole result set (the results carry no actor),
 *     so before this nothing durable named a calculator or recalculator.
 *
 * `payroll_run_calculations` is Payroll-specific, School- and run-bound,
 * append-only (no runtime UPDATE/DELETE, RLS forced) and records only facts
 * the run already has: who calculated and how many adjustments the run held
 * at that moment (checked here against the real count). It leaves with its run
 * (ON DELETE CASCADE: the run's own retention expiry or a discarded draft).
 *
 * `payroll_runs_participant_approval_guard` (BEFORE UPDATE of status /
 * approved_by_user_id, calculated -> approved), for every role, refuses an
 * approval when:
 *   - the RECORDED approver is a participant (creator, calculator or
 *     adjustment author) -- `payroll_runs_sod_check` stays for the creator; or
 *   - the results are stale: no recorded calculation, the LATEST one (by
 *     per-run ordinal) left an employment unresolved or produced nothing, or
 *     an adjustment was recorded after it (the approved output must be a
 *     complete calculation of every recorded adjustment).
 * Also: an adjustment is inserted only into a run being prepared (FOR SHARE),
 * and who approved / when never changes once the run is decided.
 * SECURITY INVOKER, pinned search_path; the decision UPDATE already runs under
 * its School's RLS context.
 *
 * Existing runs gain no backfill: who calculated them is not recorded
 * anywhere structural. A run calculated before this migration therefore
 * fails closed -- it is approved only after a recorded recalculation.
 *
 * Rolling back drops the guard, the predicate and the table (refused while
 * the table holds rows, like other Payroll evidence).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('payroll_run_id');
            $table->foreignUuid('calculated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('adjustment_count');
            $table->boolean('complete');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'payroll_run_id', 'ordinal'], 'payroll_run_calculations_ordinal_unique');

            $table->foreign(['payroll_run_id', 'school_id'], 'payroll_run_calculations_run_fk')
                ->references(['id', 'school_id'])->on('payroll_runs')->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_run_calculations_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
            BEGIN
                -- FOR SHARE: a concurrent approval (which updates the run) is ordered before or after this row.
                SELECT status INTO v_status FROM public.payroll_runs WHERE id = NEW.payroll_run_id AND school_id = NEW.school_id FOR SHARE;
                IF v_status IS DISTINCT FROM 'draft' AND v_status IS DISTINCT FROM 'calculated' THEN
                    RAISE EXCEPTION 'payroll_run_calculations: run % is % -- it is not being prepared', NEW.payroll_run_id, v_status
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.adjustment_count <> (SELECT count(*) FROM public.payroll_adjustments a WHERE a.school_id = NEW.school_id AND a.payroll_run_id = NEW.payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_run_calculations: adjustment_count must be the run''s current adjustment count'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.ordinal <> 1 + (SELECT count(*) FROM public.payroll_run_calculations c WHERE c.school_id = NEW.school_id AND c.payroll_run_id = NEW.payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_run_calculations: ordinal must follow the run''s last calculation'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_run_calculations_guard BEFORE INSERT ON payroll_run_calculations
                FOR EACH ROW EXECUTE FUNCTION public.payroll_run_calculations_guard();

            -- An adjustment changes a run only while it is being prepared (the service already refuses it
            -- otherwise); FOR SHARE orders it against a concurrent approval.
            CREATE FUNCTION public.payroll_adjustments_require_preparing_run() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
            BEGIN
                SELECT status INTO v_status FROM public.payroll_runs WHERE id = NEW.payroll_run_id AND school_id = NEW.school_id FOR SHARE;
                IF v_status IS DISTINCT FROM 'draft' AND v_status IS DISTINCT FROM 'calculated' THEN
                    RAISE EXCEPTION 'payroll_adjustments: run % is % -- it is not being prepared', NEW.payroll_run_id, v_status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_adjustments_require_preparing_run BEFORE INSERT ON payroll_adjustments
                FOR EACH ROW EXECUTE FUNCTION public.payroll_adjustments_require_preparing_run();

            -- Who approved, and when, never changes once the run is decided.
            CREATE FUNCTION public.payroll_runs_freeze_approval() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'payroll_runs_participant_approval_guard: payroll run % approval is final', OLD.id
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER payroll_runs_freeze_approval
                BEFORE UPDATE OF approved_by_user_id, approved_at ON payroll_runs
                FOR EACH ROW
                WHEN (OLD.status IN ('approved', 'posted') AND (NEW.approved_by_user_id IS DISTINCT FROM OLD.approved_by_user_id OR NEW.approved_at IS DISTINCT FROM OLD.approved_at))
                EXECUTE FUNCTION public.payroll_runs_freeze_approval();

            CREATE FUNCTION public.payroll_run_has_participant(p_school uuid, p_run uuid, p_user uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (SELECT 1 FROM public.payroll_runs r WHERE r.school_id = p_school AND r.id = p_run AND r.prepared_by_user_id = p_user)
                    OR EXISTS (SELECT 1 FROM public.payroll_run_calculations c WHERE c.school_id = p_school AND c.payroll_run_id = p_run AND c.calculated_by_user_id = p_user)
                    OR EXISTS (SELECT 1 FROM public.payroll_adjustments a WHERE a.school_id = p_school AND a.payroll_run_id = p_run AND a.actor_user_id = p_user)
            $$;

            CREATE FUNCTION public.payroll_runs_refuse_participant_approval() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_calculated integer;
                v_complete boolean;
                v_adjustments integer;
            BEGIN
                IF public.payroll_run_has_participant(NEW.school_id, NEW.id, NEW.approved_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_runs_participant_approval_guard: payroll run % is approved by someone who prepared it', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                -- The LATEST calculation decides: it included every recorded adjustment and resolved every employment.
                SELECT c.adjustment_count, c.complete INTO v_calculated, v_complete FROM public.payroll_run_calculations c
                 WHERE c.school_id = NEW.school_id AND c.payroll_run_id = NEW.id ORDER BY c.ordinal DESC LIMIT 1;
                SELECT count(*) INTO v_adjustments FROM public.payroll_adjustments a WHERE a.school_id = NEW.school_id AND a.payroll_run_id = NEW.id;
                IF v_calculated IS NULL OR v_calculated < v_adjustments OR NOT v_complete THEN
                    RAISE EXCEPTION 'payroll_runs_participant_approval_guard: payroll run % changed after its last recorded calculation', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payroll_runs_participant_approval_guard
                BEFORE UPDATE OF status, approved_by_user_id ON payroll_runs
                FOR EACH ROW
                WHEN (OLD.status = 'calculated' AND NEW.status = 'approved')
                EXECUTE FUNCTION public.payroll_runs_refuse_participant_approval();

            REVOKE ALL ON FUNCTION public.payroll_run_calculations_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_adjustments_require_preparing_run() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_runs_freeze_approval() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_has_participant(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_runs_refuse_participant_approval() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_run_has_participant(uuid, uuid, uuid) TO school_os_app;
            SQL);

        TenantRls::enable('payroll_run_calculations');
        TenantRls::makeAppendOnly('payroll_run_calculations');
    }

    public function down(): void
    {
        if (DB::table('payroll_run_calculations')->exists()) {
            throw new RuntimeException('Refusing to roll back: payroll_run_calculations holds approval provenance (ORG.8B).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payroll_runs_freeze_approval ON payroll_runs;
            DROP FUNCTION IF EXISTS public.payroll_runs_freeze_approval();
            DROP TRIGGER IF EXISTS trg_payroll_adjustments_require_preparing_run ON payroll_adjustments;
            DROP FUNCTION IF EXISTS public.payroll_adjustments_require_preparing_run();
            DROP TRIGGER IF EXISTS payroll_runs_participant_approval_guard ON payroll_runs;
            DROP FUNCTION IF EXISTS public.payroll_runs_refuse_participant_approval();
            DROP FUNCTION IF EXISTS public.payroll_run_has_participant(uuid, uuid, uuid);
            DROP TRIGGER IF EXISTS trg_payroll_run_calculations_guard ON payroll_run_calculations;
            DROP FUNCTION IF EXISTS public.payroll_run_calculations_guard();
            SQL);
        TenantRls::disable('payroll_run_calculations');
        Schema::dropIfExists('payroll_run_calculations');
    }
};
