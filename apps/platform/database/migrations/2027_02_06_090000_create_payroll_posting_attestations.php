<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C4 (PAY-16, OD-HR-22): a posting never silently relies on approval
 * independence a later reversal took away.
 *
 * The exception is one derived fact, never a stored flag: the run's approver is
 * now an author in `payroll_run_first_paid_compensation` for the run's approved
 * calculation. The approval guard refuses that at approval, so it can only have
 * arisen afterwards. `payroll_run_independence_exception()` answers it, and
 * `payroll_run_independence_version()` is the evidence an attestation binds: the
 * approved calculation, the approver, the implicated assignments and the reversals
 * of the runs that had discharged them. NULL means no exception.
 *
 * `payroll_posting_attestations` is one append-only row per run, written in the
 * posting transaction by PayrollPostingService. It is a separate statement by the
 * poster, never a new approval: the run's approval, calculation and history stay
 * exactly as they were.
 *
 * The database enforces the relationships: an attestation names the run's approved
 * calculation, the current evidence, the original posting and that posting's actor,
 * who may be no maker of the run. `trg_payroll_runs_posting_independence` refuses
 * approved -> posted with an open exception and no such attestation, for every
 * role. Whether a person read the evidence is outside what a database can know
 * (rule 102): that is the service's explicit acknowledgement.
 *
 * Both triggers take the compensation lock SHARED, like an approval, so a reversal
 * (EXCLUSIVE) is ordered wholly before or after them.
 *
 * Retention: the attestation is approval and posting evidence, so it leaves with
 * its posting and calculation, and no row is ever backfilled.
 *
 * Rollback refuses once an attestation exists. FORCE RLS would hide its rows from
 * a migration role without BYPASSRLS (PAY-15), so `down()` lifts FORCE on this table
 * first, inside the migration's own transaction, to read what is really there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_posting_attestations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('payroll_run_calculation_id');
            $table->uuid('payroll_run_posting_id');
            $table->char('evidence_version', 64);
            $table->string('statement_code', 40);
            $table->foreignUuid('attested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'payroll_run_id'], 'payroll_posting_attestations_one_per_run');
            $table->index(['payroll_run_calculation_id'], 'payroll_posting_attestations_calculation_idx');
            $table->index(['payroll_run_posting_id'], 'payroll_posting_attestations_posting_idx');
            $table->index(['attested_by_user_id'], 'payroll_posting_attestations_actor_idx');

            $table->foreign(['payroll_run_calculation_id', 'payroll_run_id', 'school_id'], 'payroll_posting_attestations_calculation_fk')
                ->references(['id', 'payroll_run_id', 'school_id'])->on('payroll_run_calculations')->cascadeOnDelete();
            $table->foreign(['payroll_run_posting_id', 'school_id'], 'payroll_posting_attestations_posting_fk')
                ->references(['id', 'school_id'])->on('payroll_run_postings')->cascadeOnDelete();
        });

        TenantRls::enable('payroll_posting_attestations');
        TenantRls::makeAppendOnly('payroll_posting_attestations');
        DB::statement("ALTER TABLE payroll_posting_attestations ADD CONSTRAINT payroll_posting_attestations_statement_check CHECK (statement_code IN ('independence_changed_v1'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_run_independence_exception(p_school uuid, p_run uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT f.compensation_assignment_id, f.employment_record_id
                  FROM public.payroll_runs r
                 CROSS JOIN LATERAL public.payroll_run_first_paid_compensation(r.school_id, r.id, r.approved_calculation_id) f
                 WHERE r.school_id = p_school AND r.id = p_run AND r.run_kind = 'regular' AND r.status IN ('approved', 'posted')
                   AND r.approved_calculation_id IS NOT NULL AND f.assigned_by_user_id = r.approved_by_user_id
            $$;

            CREATE FUNCTION public.payroll_run_independence_version(p_school uuid, p_run uuid) RETURNS text
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                WITH implicated AS (
                    SELECT e.compensation_assignment_id FROM public.payroll_run_independence_exception(p_school, p_run) e
                ), reversals AS (
                    SELECT DISTINCT rp.id
                      FROM implicated i
                      JOIN public.payroll_run_calculation_sources os
                        ON os.school_id = p_school AND os.compensation_assignment_id = i.compensation_assignment_id AND os.payroll_run_id <> p_run AND os.basis = 'structure'
                      JOIN public.payroll_runs o
                        ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id AND o.run_kind = 'regular'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = os.school_id AND a.id = os.compensation_assignment_id AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                      JOIN public.payroll_run_postings rp
                        ON rp.school_id = o.school_id AND rp.payroll_run_id = o.id AND rp.posting_kind = 'reversal'
                )
                SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM implicated) THEN NULL ELSE encode(sha256(convert_to(
                           (SELECT r.approved_calculation_id::text || '|' || r.approved_by_user_id::text FROM public.payroll_runs r WHERE r.school_id = p_school AND r.id = p_run)
                           || '|' || (SELECT string_agg(i.compensation_assignment_id::text, ',' ORDER BY i.compensation_assignment_id) FROM implicated i)
                           || '|' || COALESCE((SELECT string_agg(v.id::text, ',' ORDER BY v.id) FROM reversals v), ''), 'UTF8')), 'hex') END
            $$;

            CREATE FUNCTION public.payroll_posting_attestations_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
                v_approved_calculation uuid;
                v_approver uuid;
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                SELECT r.status, r.approved_calculation_id, r.approved_by_user_id INTO v_status, v_approved_calculation, v_approver
                  FROM public.payroll_runs r WHERE r.school_id = NEW.school_id AND r.id = NEW.payroll_run_id FOR SHARE;
                IF v_status IS DISTINCT FROM 'approved' THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: only an approved run awaiting its posting is attested' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.payroll_run_calculation_id IS DISTINCT FROM v_approved_calculation THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestation names the approved calculation of its run' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.evidence_version IS DISTINCT FROM public.payroll_run_independence_version(NEW.school_id, NEW.payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestation binds the current evidence of an open exception' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.payroll_run_postings p WHERE p.school_id = NEW.school_id AND p.id = NEW.payroll_run_posting_id
                                  AND p.payroll_run_id = NEW.payroll_run_id AND p.posting_kind = 'original' AND p.actor_user_id = NEW.attested_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestation is made by the actor of its own run''s posting' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.attested_by_user_id = v_approver
                   OR public.payroll_run_has_participant(NEW.school_id, NEW.payroll_run_id, NEW.attested_by_user_id)
                   OR EXISTS (SELECT 1 FROM public.payroll_run_first_paid_compensation(NEW.school_id, NEW.payroll_run_id, v_approved_calculation) f
                               WHERE f.assigned_by_user_id = NEW.attested_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestor is independent of every maker of the run' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_posting_attestations_guard
                BEFORE INSERT ON payroll_posting_attestations
                FOR EACH ROW EXECUTE FUNCTION public.payroll_posting_attestations_guard();

            CREATE FUNCTION public.payroll_runs_posting_independence_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_version text;
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                v_version := public.payroll_run_independence_version(NEW.school_id, NEW.id);
                IF v_version IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM public.payroll_posting_attestations a
                     WHERE a.school_id = NEW.school_id AND a.payroll_run_id = NEW.id AND a.payroll_run_calculation_id = NEW.approved_calculation_id
                       AND a.evidence_version = v_version AND a.attested_by_user_id = NEW.posted_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_runs_posting_independence_guard: payroll run % lost its approval independence and has no attestation', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_runs_posting_independence
                BEFORE UPDATE OF status ON payroll_runs
                FOR EACH ROW WHEN (OLD.status = 'approved' AND NEW.status = 'posted')
                EXECUTE FUNCTION public.payroll_runs_posting_independence_guard();

            REVOKE ALL ON FUNCTION public.payroll_run_independence_exception(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_independence_version(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_posting_attestations_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_runs_posting_independence_guard() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_run_independence_exception(uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_run_independence_version(uuid, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        // PAY-15: lifted inside this transaction, so a role without BYPASSRLS reads the real rows. A refusal rolls it back.
        DB::statement('ALTER TABLE payroll_posting_attestations NO FORCE ROW LEVEL SECURITY');
        if (DB::table('payroll_posting_attestations')->exists()) {
            throw new RuntimeException('Refusing to roll back: posting attestations are recorded (HR.R1-C4). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_payroll_runs_posting_independence ON payroll_runs;
            DROP FUNCTION public.payroll_runs_posting_independence_guard();
            DROP TRIGGER trg_payroll_posting_attestations_guard ON payroll_posting_attestations;
            DROP FUNCTION public.payroll_posting_attestations_guard();
            DROP FUNCTION public.payroll_run_independence_version(uuid, uuid);
            DROP FUNCTION public.payroll_run_independence_exception(uuid, uuid);
            SQL);

        TenantRls::disable('payroll_posting_attestations');
        Schema::dropIfExists('payroll_posting_attestations');
    }
};
