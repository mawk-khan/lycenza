<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C7 (OD-HR-25): the history of User-Employee links, for Payroll integrity.
 *
 * `employees.user_id` stays the one current link. It was also the only record of
 * who an Employee is, so an ordinary unlink let a person approve, attest to and
 * review their own pay change, reproduced on all three paths. A link states that
 * this account is this person, and an unlink does not change who the person is. So
 * every link not corrected as erroneous now counts, at any date, but only for a run
 * paying THAT Employee's change first: never for Payroll generally.
 *
 * `employee_account_links` is written by a trigger on `employees.user_id`, so no
 * writer can change the current link without leaving history, raw SQL included.
 * The runtime role can only read it: it cannot insert, update or delete a row. A
 * correction goes through `employee_account_link_void()`, which marks a row and
 * never removes it.
 *
 * Cutover: current links are recorded as `cutover`, stating only that the link
 * existed when this ran. Nothing is inferred about earlier periods, and nothing is
 * mined from names, emails or audit text.
 *
 * Retention (OD-HR-25): evidence of the Employee, unit `hr_evidence`, leaving with the
 * Employee (FK cascade). The retention service already keeps a compensation assignment
 * while a calculation source names it, so a retained run keeps its Employee and their
 * link history. `payroll_run_calculation_sources_assignment_fk` goes from CASCADE to
 * RESTRICT so the database says the same: an assignment a decision read cannot vanish
 * before the run does. Nothing here keeps a compensation master for its own sake:
 * the run's expiry releases it.
 *
 * `linked_at` is both the recorded and the effective time: a link cannot be backdated.
 * `linked_by_user_id` comes from the service. NULL means a write outside it: the
 * history is still recorded, and who acted stays an application boundary (rule 102).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('origin', 10);
            $table->timestamp('linked_at', 6)->useCurrent();
            $table->foreignUuid('linked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at', 6)->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at', 6)->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 20)->nullable();

            $table->unique(['id', 'school_id'], 'employee_account_links_id_school_unique');
            $table->index(['school_id', 'employee_id', 'user_id'], 'employee_account_links_employee_user_idx');
            $table->index(['school_id', 'user_id'], 'employee_account_links_user_idx');
            $table->index(['linked_by_user_id'], 'employee_account_links_linked_by_idx');
            $table->index(['ended_by_user_id'], 'employee_account_links_ended_by_idx');
            $table->index(['voided_by_user_id'], 'employee_account_links_voided_by_idx');

            // History leaves with its Employee (hr_evidence), never before.
            $table->foreign(['employee_id', 'school_id'], 'employee_account_links_employee_fk')
                ->references(['id', 'school_id'])->on('employees')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX employee_account_links_one_open_per_employee ON employee_account_links (school_id, employee_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX employee_account_links_one_open_per_user ON employee_account_links (school_id, user_id) WHERE ended_at IS NULL');
        DB::statement("ALTER TABLE employee_account_links ADD CONSTRAINT employee_account_links_origin_check CHECK (origin IN ('recorded', 'cutover'))");
        DB::statement('ALTER TABLE employee_account_links ADD CONSTRAINT employee_account_links_ended_check CHECK ((ended_by_user_id IS NULL OR ended_at IS NOT NULL) AND (ended_at IS NULL OR ended_at >= linked_at))');
        DB::statement("ALTER TABLE employee_account_links ADD CONSTRAINT employee_account_links_void_check CHECK (
            (voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
            OR (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND ended_at IS NOT NULL AND void_reason IN ('wrong_person', 'setup_error')))");

        TenantRls::enable('employee_account_links');
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON employee_account_links FROM school_os_app');

        // Cutover: what is known now, and nothing earlier. Before the trigger, so this is the only such write,
        // and locked until the transaction ends, so no link can change between this read and the trigger.
        DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
        DB::statement("INSERT INTO employee_account_links (id, school_id, employee_id, user_id, origin)
            SELECT gen_random_uuid(), school_id, id, user_id, 'cutover' FROM employees WHERE user_id IS NOT NULL");

        DB::statement('ALTER TABLE payroll_run_calculation_sources DROP CONSTRAINT payroll_run_calculation_sources_assignment_fk');
        DB::statement('ALTER TABLE payroll_run_calculation_sources ADD CONSTRAINT payroll_run_calculation_sources_assignment_fk
            FOREIGN KEY (compensation_assignment_id, school_id) REFERENCES employee_compensation_assignments (id, school_id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.employees_record_account_link() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_actor uuid := NULLIF(current_setting('app.link_actor_user_id', true), '')::uuid;
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.user_id IS NOT NULL THEN
                    UPDATE public.employee_account_links SET ended_at = clock_timestamp(), ended_by_user_id = v_actor
                     WHERE school_id = OLD.school_id AND employee_id = OLD.id AND ended_at IS NULL;
                END IF;
                IF NEW.user_id IS NOT NULL THEN
                    INSERT INTO public.employee_account_links (id, school_id, employee_id, user_id, origin, linked_at, linked_by_user_id)
                    VALUES (gen_random_uuid(), NEW.school_id, NEW.id, NEW.user_id, 'recorded', clock_timestamp(), v_actor);
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER trg_employees_record_account_link_insert
                AFTER INSERT ON employees
                FOR EACH ROW WHEN (NEW.user_id IS NOT NULL)
                EXECUTE FUNCTION public.employees_record_account_link();

            CREATE TRIGGER trg_employees_record_account_link_update
                AFTER UPDATE OF user_id ON employees
                FOR EACH ROW WHEN (OLD.user_id IS DISTINCT FROM NEW.user_id)
                EXECUTE FUNCTION public.employees_record_account_link();

            -- Marks a row and never removes it. Only an ended link: the service unlinks first, in the same transaction.
            CREATE FUNCTION public.employee_account_link_void(p_school uuid, p_link uuid, p_reason text, p_actor uuid) RETURNS void
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_employee uuid;
            BEGIN
                IF p_school::text IS DISTINCT FROM current_setting('app.current_school_id', true) THEN
                    RAISE EXCEPTION 'employee_account_link_void: not this School' USING ERRCODE = 'insufficient_privilege';
                END IF;
                -- A correction changes what a decision reads, so it is ordered against decisions like any link change.
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('payroll.compensation:' || p_school::text, 0));
                -- Who acted stays an application boundary, but a forged call must at least name someone who could have.
                IF NOT EXISTS (
                    SELECT 1 FROM public.school_memberships m
                      JOIN public.users u ON u.id = m.user_id AND NOT u.is_disabled
                      JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL AND a.campus_id IS NULL
                      JOIN public.role_capabilities rc ON rc.role_id = a.role_id AND rc.capability_key = 'hr.employees.manage'
                     WHERE m.school_id = p_school AND m.user_id = p_actor AND m.status = 'active'
                ) THEN
                    RAISE EXCEPTION 'employee_account_link_void: the actor holds no whole-School HR authority here' USING ERRCODE = 'insufficient_privilege';
                END IF;
                SELECT employee_id INTO v_employee FROM public.employee_account_links WHERE school_id = p_school AND id = p_link;
                IF EXISTS (SELECT 1 FROM public.employee_account_links l
                            WHERE l.school_id = p_school AND l.employee_id = v_employee AND l.user_id = p_actor) THEN
                    RAISE EXCEPTION 'employee_account_link_void: nobody corrects a link of an Employee they are linked to' USING ERRCODE = 'check_violation';
                END IF;
                UPDATE public.employee_account_links SET voided_at = clock_timestamp(), voided_by_user_id = p_actor, void_reason = p_reason
                 WHERE school_id = p_school AND id = p_link AND ended_at IS NOT NULL AND voided_at IS NULL AND user_id IS DISTINCT FROM p_actor;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'employee_account_link_void: only an ended, uncorrected link of someone else' USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_run_first_paid_compensation(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid, assigned_by_user_id uuid, basis text)
                LANGUAGE sql STABLE SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT s.compensation_assignment_id, s.employment_record_id, a.assigned_by_user_id, s.basis::text
                  FROM public.payroll_run_calculation_sources s
                  JOIN public.employee_compensation_assignments a ON a.id = s.compensation_assignment_id AND a.school_id = s.school_id
                  LEFT JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                 WHERE s.school_id = p_school AND s.payroll_run_id = p_run AND s.payroll_run_calculation_id = p_calc
                   AND NOT EXISTS (
                       SELECT 1 FROM public.payroll_runs o
                         JOIN public.payroll_run_calculation_sources os
                           ON os.school_id = o.school_id AND os.payroll_run_id = o.id AND os.payroll_run_calculation_id = o.approved_calculation_id
                        WHERE o.school_id = p_school AND o.id <> p_run AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                          -- Paid means PRICED from the assignment: a run whose period a manual override priced never
                          -- had the assignment's own values approved (security review 2026-11-05).
                          AND os.basis = 'structure'
                          AND os.compensation_assignment_id = s.compensation_assignment_id
                          -- HR.R1-C3: a reversed run no longer stands (its status never changes; ADR 0034).
                          AND NOT EXISTS (SELECT 1 FROM public.payroll_run_postings rp
                                           WHERE rp.school_id = o.school_id AND rp.payroll_run_id = o.id AND rp.posting_kind = 'reversal')
                          -- HR.R1-C3: only an approval independent of the author discharges the attribution.
                          AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                          -- HR.R1-C6, OD-HR-25: nor is an approval by the change's own Employee, on any uncorrected link.
                          AND NOT EXISTS (SELECT 1 FROM public.employee_account_links l
                                           WHERE l.school_id = o.school_id AND l.employee_id = er.employee_id
                                             AND l.user_id = o.approved_by_user_id AND l.voided_at IS NULL))
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_run_independence_exception(p_school uuid, p_run uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT f.compensation_assignment_id, f.employment_record_id
                  FROM public.payroll_runs r
                 CROSS JOIN LATERAL public.payroll_run_first_paid_compensation(r.school_id, r.id, r.approved_calculation_id) f
                 WHERE r.school_id = p_school AND r.id = p_run AND r.run_kind = 'regular' AND r.status IN ('approved', 'posted')
                   AND r.approved_calculation_id IS NOT NULL
                   AND (f.assigned_by_user_id = r.approved_by_user_id
                        OR EXISTS (SELECT 1 FROM public.employment_records er
                                     JOIN public.employee_account_links l ON l.school_id = er.school_id AND l.employee_id = er.employee_id
                                    WHERE er.school_id = p_school AND er.id = f.employment_record_id
                                      AND l.user_id = r.approved_by_user_id AND l.voided_at IS NULL))
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_run_first_paid_beneficiaries(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (employee_id uuid, user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- OD-HR-25: every uncorrected link, current or ended. One row per person; NULL user means none is known.
                SELECT DISTINCT er.employee_id, l.user_id
                  FROM public.payroll_run_first_paid_compensation(p_school, p_run, p_calc) f
                  JOIN public.employment_records er ON er.school_id = p_school AND er.id = f.employment_record_id
                  LEFT JOIN public.employee_account_links l ON l.school_id = p_school AND l.employee_id = er.employee_id AND l.voided_at IS NULL
            $$;

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

            CREATE OR REPLACE FUNCTION public.payroll_reversal_reconciliation_subjects(p_school uuid, p_reconciliation uuid)
                RETURNS TABLE (assigned_by_user_id uuid, employee_id uuid, user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                WITH obligation AS MATERIALIZED (
                    SELECT c.payroll_run_id, c.payroll_run_calculation_id, c.first_paid_assignments
                      FROM public.payroll_reversal_reconciliations c WHERE c.school_id = p_school AND c.id = p_reconciliation
                ), assignments AS MATERIALIZED (
                    SELECT unnest(o.first_paid_assignments) AS id FROM obligation o
                    UNION
                    SELECT f.compensation_assignment_id FROM obligation o
                     CROSS JOIN LATERAL public.payroll_run_first_paid_compensation(p_school, o.payroll_run_id, o.payroll_run_calculation_id) f
                )
                SELECT DISTINCT a.assigned_by_user_id, er.employee_id, l.user_id
                  FROM assignments x
                  JOIN public.employee_compensation_assignments a ON a.school_id = p_school AND a.id = x.id
                  JOIN public.employment_records er ON er.school_id = p_school AND er.id = a.employment_record_id
                  LEFT JOIN public.employee_account_links l ON l.school_id = p_school AND l.employee_id = er.employee_id AND l.voided_at IS NULL
            $$;

            REVOKE ALL ON FUNCTION public.employees_record_account_link() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP are atomic and see every School.
        DB::statement('ALTER TABLE employee_account_links NO FORCE ROW LEVEL SECURITY');
        if (DB::table('employee_account_links')->where(fn ($q) => $q->where('origin', 'recorded')->orWhereNotNull('voided_at')->orWhereNotNull('ended_at'))->exists()) {
            throw new RuntimeException('Refusing to roll back: account link history is recorded (HR.R1-C7). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employees_record_account_link_update ON employees;
            DROP TRIGGER trg_employees_record_account_link_insert ON employees;
            DROP FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid);
            DROP FUNCTION public.employees_record_account_link();

            CREATE OR REPLACE FUNCTION public.payroll_run_first_paid_compensation(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid, assigned_by_user_id uuid, basis text)
                LANGUAGE sql STABLE SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT s.compensation_assignment_id, s.employment_record_id, a.assigned_by_user_id, s.basis::text
                  FROM public.payroll_run_calculation_sources s
                  JOIN public.employee_compensation_assignments a ON a.id = s.compensation_assignment_id AND a.school_id = s.school_id
                  LEFT JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                  LEFT JOIN public.employees e ON e.school_id = er.school_id AND e.id = er.employee_id
                 WHERE s.school_id = p_school AND s.payroll_run_id = p_run AND s.payroll_run_calculation_id = p_calc
                   AND NOT EXISTS (
                       SELECT 1 FROM public.payroll_runs o
                         JOIN public.payroll_run_calculation_sources os
                           ON os.school_id = o.school_id AND os.payroll_run_id = o.id AND os.payroll_run_calculation_id = o.approved_calculation_id
                        WHERE o.school_id = p_school AND o.id <> p_run AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                          -- Paid means PRICED from the assignment: a run whose period a manual override priced never
                          -- had the assignment's own values approved (security review 2026-11-05).
                          AND os.basis = 'structure'
                          AND os.compensation_assignment_id = s.compensation_assignment_id
                          -- HR.R1-C3: a reversed run no longer stands (its status never changes; ADR 0034).
                          AND NOT EXISTS (SELECT 1 FROM public.payroll_run_postings rp
                                           WHERE rp.school_id = o.school_id AND rp.payroll_run_id = o.id AND rp.posting_kind = 'reversal')
                          -- HR.R1-C3: only an approval independent of the author discharges the attribution.
                          AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                          -- HR.R1-C6: nor is an approval by the change's own Employee. Without this, two runs the Employee
                          -- approved discharge the change for each other, and reversing the real approval flags nothing.
                          AND o.approved_by_user_id IS DISTINCT FROM e.user_id)
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_run_independence_exception(p_school uuid, p_run uuid)
                RETURNS TABLE (compensation_assignment_id uuid, employment_record_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT f.compensation_assignment_id, f.employment_record_id
                  FROM public.payroll_runs r
                 CROSS JOIN LATERAL public.payroll_run_first_paid_compensation(r.school_id, r.id, r.approved_calculation_id) f
                 WHERE r.school_id = p_school AND r.id = p_run AND r.run_kind = 'regular' AND r.status IN ('approved', 'posted')
                   AND r.approved_calculation_id IS NOT NULL
                   AND (f.assigned_by_user_id = r.approved_by_user_id
                        OR EXISTS (SELECT 1 FROM public.employment_records er
                                     JOIN public.employees e ON e.school_id = er.school_id AND e.id = er.employee_id
                                    WHERE er.school_id = p_school AND er.id = f.employment_record_id AND e.user_id = r.approved_by_user_id))
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_run_first_paid_beneficiaries(p_school uuid, p_run uuid, p_calc uuid)
                RETURNS TABLE (employee_id uuid, user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT DISTINCT e.id, e.user_id
                  FROM public.payroll_run_first_paid_compensation(p_school, p_run, p_calc) f
                  JOIN public.employment_records er ON er.school_id = p_school AND er.id = f.employment_record_id
                  JOIN public.employees e ON e.school_id = p_school AND e.id = er.employee_id
            $$;

            CREATE OR REPLACE FUNCTION public.payroll_reversal_affected_runs(p_school uuid, p_reversal uuid)
                RETURNS TABLE (payroll_run_id uuid, status text)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                -- Each step once, never per row: a run's exception costs a pass over its first-paid set, so asking it
                -- for every source row is quadratic in the run's size, and this runs under the exclusive lock.
                WITH discharged AS MATERIALIZED (
                    -- Only what the reversed run discharged: priced from the assignment, approved independently of its author.
                    SELECT s.payroll_run_id AS reversed_run_id, s.compensation_assignment_id, a.assigned_by_user_id, e.user_id AS employee_user_id
                      FROM public.payroll_run_postings rp
                      JOIN public.payroll_runs r ON r.school_id = rp.school_id AND r.id = rp.payroll_run_id
                      JOIN public.payroll_run_calculation_sources s
                        ON s.school_id = r.school_id AND s.payroll_run_id = r.id AND s.payroll_run_calculation_id = r.approved_calculation_id AND s.basis = 'structure'
                      JOIN public.employee_compensation_assignments a
                        ON a.school_id = s.school_id AND a.id = s.compensation_assignment_id AND r.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
                      JOIN public.employment_records er ON er.school_id = s.school_id AND er.id = s.employment_record_id
                      JOIN public.employees e ON e.school_id = er.school_id AND e.id = er.employee_id
                     WHERE r.approved_by_user_id IS DISTINCT FROM e.user_id AND rp.school_id = p_school AND rp.id = p_reversal AND rp.posting_kind = 'reversal'
                ), candidates AS MATERIALIZED (
                    SELECT DISTINCT o.id, o.status::text AS status
                      FROM discharged d
                      JOIN public.payroll_run_calculation_sources os
                        ON os.school_id = p_school AND os.compensation_assignment_id = d.compensation_assignment_id AND os.payroll_run_id <> d.reversed_run_id
                      JOIN public.payroll_runs o
                        ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
                       AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
                       AND (o.approved_by_user_id = d.assigned_by_user_id OR o.approved_by_user_id = d.employee_user_id)
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

            CREATE OR REPLACE FUNCTION public.payroll_reversal_reconciliation_subjects(p_school uuid, p_reconciliation uuid)
                RETURNS TABLE (assigned_by_user_id uuid, employee_id uuid, user_id uuid)
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                WITH obligation AS MATERIALIZED (
                    SELECT c.payroll_run_id, c.payroll_run_calculation_id, c.first_paid_assignments
                      FROM public.payroll_reversal_reconciliations c WHERE c.school_id = p_school AND c.id = p_reconciliation
                ), assignments AS MATERIALIZED (
                    SELECT unnest(o.first_paid_assignments) AS id FROM obligation o
                    UNION
                    SELECT f.compensation_assignment_id FROM obligation o
                     CROSS JOIN LATERAL public.payroll_run_first_paid_compensation(p_school, o.payroll_run_id, o.payroll_run_calculation_id) f
                )
                SELECT DISTINCT a.assigned_by_user_id, e.id, e.user_id
                  FROM assignments x
                  JOIN public.employee_compensation_assignments a ON a.school_id = p_school AND a.id = x.id
                  JOIN public.employment_records er ON er.school_id = p_school AND er.id = a.employment_record_id
                  JOIN public.employees e ON e.school_id = p_school AND e.id = er.employee_id
            $$;
            SQL);

        DB::statement('ALTER TABLE payroll_run_calculation_sources DROP CONSTRAINT payroll_run_calculation_sources_assignment_fk');
        DB::statement('ALTER TABLE payroll_run_calculation_sources ADD CONSTRAINT payroll_run_calculation_sources_assignment_fk
            FOREIGN KEY (compensation_assignment_id, school_id) REFERENCES employee_compensation_assignments (id, school_id) ON DELETE CASCADE');
        TenantRls::disable('employee_account_links');
        Schema::dropIfExists('employee_account_links');
    }
};
