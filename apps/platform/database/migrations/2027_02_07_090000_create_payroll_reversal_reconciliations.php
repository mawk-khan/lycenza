<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23; PAY-19, OD-HR-24).
 *
 * PAY-18. A reversal can take independence from a run that has ALREADY posted:
 * its approver is then the author of a change it pays first, and no check at
 * posting can reach that. `payroll_reversal_reconciliations` records one OPEN
 * obligation per (reversal, affected posted run), in the reversal's own
 * transaction; `payroll_reversal_reconciliation_reviews` is the independent
 * review that closes it. Both are append-only, and there is no status column:
 * an obligation is open exactly while it has no review, so nothing can be updated
 * into looking closed. A review is never a new approval and never rewrites the
 * run: it records that someone independent read the real chronology.
 *
 * `payroll_reversal_affected_runs` is the one definition of "affected": a run
 * whose independence exception now contains a change the reversed run discharged.
 * That compares the reversal with the exception itself, so a run that merely has
 * some unrelated open exception is not affected (PAY-20). A run already reversed
 * has no live effect left, so it is not affected either.
 *
 * `payroll_run_postings_reconciliation_required` is deferred to commit, so a
 * reversal cannot commit with an affected posted run left without its obligation,
 * whichever code wrote the reversal.
 *
 * PAY-19. `payroll_run_first_paid_beneficiaries` answers "whose compensation
 * changes does this run pay first", through `employees.user_id` only: never a
 * name, email or code. A linked person is refused at approval, at the C4
 * attestation and at this review (owner decision 2026-11-06). An Employee with no
 * linked account is reported, never treated as proof of no conflict.
 *
 * `employees.user_id` is the current link only. Changing it takes the
 * compensation lock exclusively, so it is ordered against every decision (shared)
 * that read it.
 *
 * Who acted stays an application boundary (rule 102): these guards check the
 * recorded actor. The database cannot know a human's identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_reversal_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('payroll_run_calculation_id');
            $table->uuid('reversal_posting_id');
            $table->char('evidence_version', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id'], 'payroll_reversal_reconciliations_id_school_unique');
            $table->unique(['school_id', 'reversal_posting_id', 'payroll_run_id'], 'payroll_reversal_reconciliations_one_per_reversal');
            $table->index(['school_id', 'created_at'], 'payroll_reversal_reconciliations_school_idx');
            $table->index(['school_id', 'payroll_run_id'], 'payroll_reversal_reconciliations_run_idx');
            $table->index(['payroll_run_calculation_id'], 'payroll_reversal_reconciliations_calculation_idx');
            $table->index(['reversal_posting_id'], 'payroll_reversal_reconciliations_posting_idx');

            $table->foreign(['payroll_run_calculation_id', 'payroll_run_id', 'school_id'], 'payroll_reversal_reconciliations_calculation_fk')
                ->references(['id', 'payroll_run_id', 'school_id'])->on('payroll_run_calculations')->cascadeOnDelete();
            $table->foreign(['reversal_posting_id', 'school_id'], 'payroll_reversal_reconciliations_posting_fk')
                ->references(['id', 'school_id'])->on('payroll_run_postings')->cascadeOnDelete();
        });

        Schema::create('payroll_reversal_reconciliation_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('reconciliation_id');
            $table->char('evidence_version', 64);
            $table->string('statement_code', 40);
            // OD-HR-24: first-paid Employees with no linked account, so the conflict check could not see them.
            $table->unsignedInteger('unlinked_employee_count');
            $table->foreignUuid('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'reconciliation_id'], 'payroll_reversal_reconciliation_reviews_one_per_obligation');
            $table->index(['reconciliation_id'], 'payroll_reversal_reconciliation_reviews_reconciliation_idx');
            $table->index(['reviewed_by_user_id'], 'payroll_reversal_reconciliation_reviews_actor_idx');

            $table->foreign(['reconciliation_id', 'school_id'], 'payroll_reversal_reconciliation_reviews_reconciliation_fk')
                ->references(['id', 'school_id'])->on('payroll_reversal_reconciliations')->cascadeOnDelete();
        });

        foreach (['payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews'] as $table) {
            TenantRls::enable($table);
            TenantRls::makeAppendOnly($table);
        }
        DB::statement("ALTER TABLE payroll_reversal_reconciliation_reviews ADD CONSTRAINT payroll_reversal_reconciliation_reviews_statement_check CHECK (statement_code IN ('reversal_impact_reviewed_v1'))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_run_first_paid_beneficiaries(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (employee_id uuid, user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT DISTINCT e.id, e.user_id
                  FROM public.payroll_run_first_paid_compensation(p_school, p_run, p_calc) f
                  JOIN public.employment_records er ON er.school_id = p_school AND er.id = f.employment_record_id
                  JOIN public.employees e ON e.school_id = p_school AND e.id = er.employee_id
            $$;

            CREATE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- Each step once, never per row: a run's exception costs a pass over its first-paid set, so asking it
                -- for every source row is quadratic in the run's size, and this runs under the exclusive lock.
                WITH discharged AS MATERIALIZED (
                    -- Only what the reversed run discharged: priced from the assignment, approved independently of its author.
                    SELECT s.payroll_run_id AS reversed_run_id, s.compensation_assignment_id, a.assigned_by_user_id
                      FROM public.payroll_run_postings rp
                      JOIN public.payroll_runs r ON r.school_id = rp.school_id AND r.id = rp.payroll_run_id
                      JOIN public.payroll_run_calculation_sources s
                        ON s.school_id = r.school_id AND s.payroll_run_id = r.id AND s.payroll_run_calculation_id = r.approved_calculation_id AND s.basis = 'structure'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = s.school_id AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                     WHERE rp.school_id = p_school AND rp.id = p_reversal AND rp.posting_kind = 'reversal'
                ), candidates AS MATERIALIZED (
                    SELECT DISTINCT o.id, o.status::text AS status
                      FROM discharged d
                      JOIN public.payroll_run_calculation_sources os
                        ON os.school_id = p_school AND os.compensation_assignment_id = d.compensation_assignment_id AND os.payroll_run_id <> d.reversed_run_id
                      JOIN public.payroll_runs o
                        ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
                       AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted') AND o.approved_by_user_id = d.assigned_by_user_id
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

            -- What a reviewer's page must still match: bound to this obligation, so it can never be replayed on another.
            CREATE FUNCTION public.payroll_reversal_reconciliation_version(p_school uuid, p_reconciliation uuid) RETURNS text
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT encode(sha256(convert_to(c.id::text || '|' || c.evidence_version
                           || '|' || COALESCE(public.payroll_run_independence_version(p_school, c.payroll_run_id), '')
                           || '|' || (SELECT count(*) FROM public.payroll_run_first_paid_beneficiaries(p_school, c.payroll_run_id, c.payroll_run_calculation_id) b WHERE b.user_id IS NULL)::text, 'UTF8')), 'hex')
                  FROM public.payroll_reversal_reconciliations c
                 WHERE c.school_id = p_school AND c.id = p_reconciliation
            $$;

            CREATE FUNCTION public.payroll_reversal_reconciliations_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_status text;
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
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_reversal_reconciliations_guard
                BEFORE INSERT ON payroll_reversal_reconciliations
                FOR EACH ROW EXECUTE FUNCTION public.payroll_reversal_reconciliations_guard();

            CREATE FUNCTION public.payroll_run_postings_reconciliation_required() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.payroll_reversal_affected_runs(NEW.school_id, NEW.id) f
                            WHERE f.status = 'posted'
                              AND NOT EXISTS (SELECT 1 FROM public.payroll_reversal_reconciliations c
                                               WHERE c.school_id = NEW.school_id AND c.reversal_posting_id = NEW.id AND c.payroll_run_id = f.payroll_run_id)) THEN
                    RAISE EXCEPTION 'payroll_run_postings_reconciliation_required: a reversal affected a posted run and left no reconciliation obligation' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER trg_payroll_run_postings_reconciliation_required
                AFTER INSERT ON payroll_run_postings DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW WHEN (NEW.posting_kind = 'reversal')
                EXECUTE FUNCTION public.payroll_run_postings_reconciliation_required();

            CREATE FUNCTION public.payroll_reversal_reconciliation_reviews_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_run uuid;
                v_calc uuid;
                v_approver uuid;
                v_reverser uuid;
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                SELECT c.payroll_run_id, c.payroll_run_calculation_id, r.approved_by_user_id, p.actor_user_id
                  INTO v_run, v_calc, v_approver, v_reverser
                  FROM public.payroll_reversal_reconciliations c
                  JOIN public.payroll_runs r ON r.school_id = c.school_id AND r.id = c.payroll_run_id
                  JOIN public.payroll_run_postings p ON p.school_id = c.school_id AND p.id = c.reversal_posting_id
                 WHERE c.school_id = NEW.school_id AND c.id = NEW.reconciliation_id;
                IF v_run IS NULL THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a review names an obligation of its own School' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.evidence_version IS DISTINCT FROM public.payroll_reversal_reconciliation_version(NEW.school_id, NEW.reconciliation_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a review binds the current evidence' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.unlinked_employee_count IS DISTINCT FROM (SELECT count(*) FROM public.payroll_run_first_paid_beneficiaries(NEW.school_id, v_run, v_calc) b WHERE b.user_id IS NULL) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a review records the Employees its conflict check could not see' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.reviewed_by_user_id = v_approver OR NEW.reviewed_by_user_id = v_reverser
                   OR public.payroll_run_has_participant(NEW.school_id, v_run, NEW.reviewed_by_user_id)
                   OR EXISTS (SELECT 1 FROM public.payroll_run_first_paid_compensation(NEW.school_id, v_run, v_calc) f WHERE f.assigned_by_user_id = NEW.reviewed_by_user_id)
                   OR EXISTS (SELECT 1 FROM public.payroll_run_first_paid_beneficiaries(NEW.school_id, v_run, v_calc) b WHERE b.user_id = NEW.reviewed_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_reversal_reconciliation_reviews_guard: a reviewer is independent of the run, its reversal and its first-paid changes' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_reversal_reconciliation_reviews_guard
                BEFORE INSERT ON payroll_reversal_reconciliation_reviews
                FOR EACH ROW EXECUTE FUNCTION public.payroll_reversal_reconciliation_reviews_guard();

            CREATE FUNCTION public.payroll_runs_self_beneficiary_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                IF EXISTS (SELECT 1 FROM public.payroll_run_first_paid_beneficiaries(NEW.school_id, NEW.id, NEW.approved_calculation_id) b
                            WHERE b.user_id = NEW.approved_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_runs_self_beneficiary_guard: an approver is not the Employee of a change the run pays first' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_runs_self_beneficiary
                BEFORE UPDATE OF status ON payroll_runs
                FOR EACH ROW WHEN (OLD.status = 'calculated' AND NEW.status = 'approved')
                EXECUTE FUNCTION public.payroll_runs_self_beneficiary_guard();

            CREATE FUNCTION public.payroll_posting_attestations_self_beneficiary_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                IF EXISTS (SELECT 1 FROM public.payroll_run_first_paid_beneficiaries(NEW.school_id, NEW.payroll_run_id, NEW.payroll_run_calculation_id) b
                            WHERE b.user_id = NEW.attested_by_user_id) THEN
                    RAISE EXCEPTION 'payroll_posting_attestations_guard: an attestor is not the Employee of a change the run pays first' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_posting_attestations_self_beneficiary
                BEFORE INSERT ON payroll_posting_attestations
                FOR EACH ROW EXECUTE FUNCTION public.payroll_posting_attestations_self_beneficiary_guard();

            CREATE FUNCTION public.employees_user_link_payroll_lock() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employees_user_link_payroll_lock
                BEFORE UPDATE OF user_id ON employees
                FOR EACH ROW WHEN (OLD.user_id IS DISTINCT FROM NEW.user_id)
                EXECUTE FUNCTION public.employees_user_link_payroll_lock();

            REVOKE ALL ON FUNCTION public.payroll_run_first_paid_beneficiaries(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_reversal_affected_runs(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_reversal_reconciliation_version(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_reversal_reconciliations_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_postings_reconciliation_required() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_reversal_reconciliation_reviews_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_runs_self_beneficiary_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_posting_attestations_self_beneficiary_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employees_user_link_payroll_lock() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_run_first_paid_beneficiaries(uuid, uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_reversal_affected_runs(uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_reversal_reconciliation_version(uuid, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP are atomic and see every School.
        foreach (['payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews'] as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to roll back: reconciliation obligations are recorded (HR.R1-C6). Fix forward.');
            }
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employees_user_link_payroll_lock ON employees;
            DROP FUNCTION public.employees_user_link_payroll_lock();
            DROP TRIGGER trg_payroll_posting_attestations_self_beneficiary ON payroll_posting_attestations;
            DROP FUNCTION public.payroll_posting_attestations_self_beneficiary_guard();
            DROP TRIGGER trg_payroll_runs_self_beneficiary ON payroll_runs;
            DROP FUNCTION public.payroll_runs_self_beneficiary_guard();
            DROP TRIGGER trg_payroll_reversal_reconciliation_reviews_guard ON payroll_reversal_reconciliation_reviews;
            DROP FUNCTION public.payroll_reversal_reconciliation_reviews_guard();
            DROP TRIGGER trg_payroll_run_postings_reconciliation_required ON payroll_run_postings;
            DROP FUNCTION public.payroll_run_postings_reconciliation_required();
            DROP TRIGGER trg_payroll_reversal_reconciliations_guard ON payroll_reversal_reconciliations;
            DROP FUNCTION public.payroll_reversal_reconciliations_guard();
            DROP FUNCTION public.payroll_reversal_reconciliation_version(uuid, uuid);
            DROP FUNCTION public.payroll_reversal_affected_runs(uuid, uuid);
            DROP FUNCTION public.payroll_run_first_paid_beneficiaries(uuid, uuid, uuid);
            SQL);

        TenantRls::disable('payroll_reversal_reconciliation_reviews');
        TenantRls::disable('payroll_reversal_reconciliations');
        Schema::dropIfExists('payroll_reversal_reconciliation_reviews');
        Schema::dropIfExists('payroll_reversal_reconciliations');
    }
};
