<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.3F (E21-D9 x E21-D8, ADR 0064 §21 amended, project-adopted, pending
 * legal ratification): the narrow privileged path that expires posted
 * payroll evidence 8 calendar years after the Employee's final separation,
 * and then releases an emptied payroll run's journal entries to Finance.
 *
 * Posted payroll evidence is protected: results, their lines and statutory
 * results are frozen by trigger once their run is approved; adjustments and
 * both posting tables are append-only by privilege (no runtime UPDATE or
 * DELETE). Nothing here widens a runtime privilege. Two fixed-purpose
 * functions in the E21.2B pattern (SECURITY DEFINER, `search_path` pinned,
 * every table qualified, EXECUTE for the runtime role only, never PUBLIC,
 * called only through `RetentionExpiry`) do the work, and the DATABASE
 * re-proves each unit itself:
 *
 * - `retention_expire_payroll_employee_evidence(school, employee, cutoff
 *   date, dry run)`: ONE Employee's posted payroll evidence (results with
 *   their lines and statutory results, adjustments, LWF annual charges).
 *   - the School is the caller's tenant context;
 *   - the cutoff is at least 8 calendar years before the School-local date
 *     (at most one day ahead of UTC);
 *   - the Employee row and its EmploymentRecords are locked FOR UPDATE (the
 *     lock every hire and rehire takes; every new result, adjustment or LWF
 *     charge of these employments takes FOR KEY SHARE on them, so it waits),
 *     and only then is the unit read. It has at least one EmploymentRecord, every one terminal
 *     with an `ends_on` before the cutoff (a current, future, rehired or
 *     undated Employee is refused);
 *   - every run holding the Employee's evidence is locked FOR UPDATE (the
 *     lock posting, reversal, statutory posting and a correction insert all
 *     conflict with), is POSTED, was posted before the cutoff, has every
 *     posting (original and reversal, payroll and statutory) created before
 *     the cutoff, and, when it carries statutory results, has its statutory
 *     posting. A late correction or reversal is new evidence: it keeps the
 *     Employee's evidence until it is itself old enough (longest period
 *     wins). Draft or approved runs are live working state and keep it.
 *   It deletes the LWF charges, the results (lines and statutory results
 *   cascade) and the adjustments, and stamps each run's
 *   `results_expired_at` (the first time). Journal entries and postings are
 *   never touched here.
 *
 * - `retention_expire_payroll_run(school, regular run, cutoff, dry run)`:
 *   ONE emptied regular run together with its correction runs. Every run of
 *   the group must be posted before the cutoff (8-year database floor),
 *   carry `results_expired_at` (emptied by the function above) and hold no
 *   result or adjustment, and every posting must predate the cutoff. It
 *   deletes the statutory and payroll postings (reversals first) and the
 *   runs. The journal entries stay: Finance owns them, and its own D8
 *   expiry (`retention_expire_finance_unit`, which refuses any entry a
 *   payroll posting still references) removes them only once their
 *   financial period is itself 8 years closed.
 *
 * - `results_expired_at` on `payroll_runs`: when retention first removed
 *   evidence from the run, so screens and exports can say the run's detail
 *   is no longer complete. Only the function above may set or change it.
 *
 * - The three freeze triggers also allow a DELETE inside the employee
 *   function: the transaction-local `app.payroll_retention` flag AND the
 *   table owner's privileges, which the runtime role never has (the E21.3A2
 *   guard pattern). Every other write is frozen exactly as before.
 *
 * Rollback removes the mechanism only: the functions, the flag helper, the
 * trigger patches and the marker. It refuses once any run carries the
 * marker: deleted evidence cannot be restored, and dropping the marker
 * would make an incomplete run look complete.
 */
return new class extends Migration
{
    /** @var array<string, string> function => signature */
    private const FUNCTIONS = [
        'retention_expire_payroll_employee_evidence' => 'uuid, uuid, date, boolean',
        'retention_expire_payroll_run' => 'uuid, uuid, timestamp, boolean',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN results_expired_at timestamp NULL');

        DB::unprepared(<<<'SQL'
            -- True only inside retention_expire_payroll_employee_evidence: its
            -- transaction-local flag, AND the table owner's privileges (the
            -- definer's), which the runtime role never has.
            CREATE FUNCTION payroll_retention_delete_allowed() RETURNS boolean
                LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp AS $$
                SELECT coalesce(current_setting('app.payroll_retention', true), '') = 'on'
                   AND pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = 'public.payroll_run_results'::regclass), 'MEMBER')
            $$;

            -- The marker is the retention function's alone.
            CREATE FUNCTION payroll_runs_guard_results_expired_at() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.results_expired_at IS NOT NULL THEN
                        RAISE EXCEPTION 'payroll_runs: results_expired_at is set only by payroll retention.';
                    END IF;
                ELSIF NEW.results_expired_at IS DISTINCT FROM OLD.results_expired_at AND NOT public.payroll_retention_delete_allowed() THEN
                    RAISE EXCEPTION 'payroll_runs: results_expired_at is set only by payroll retention.';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_payroll_runs_guard_results_expired_at
                BEFORE INSERT OR UPDATE ON payroll_runs
                FOR EACH ROW EXECUTE FUNCTION payroll_runs_guard_results_expired_at();

            -- The D9 payroll floor (not executable by the runtime role).
            CREATE FUNCTION retention_assert_payroll_employee_floor(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS void
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_cutoff IS NULL OR p_cutoff > ((((now() AT TIME ZONE 'UTC')::date + 1) - interval '8 years')::date) THEN
                    RAISE EXCEPTION 'retention: cutoff % is younger than the payroll evidence period (retention_floor)', p_cutoff;
                END IF;
                PERFORM 1 FROM public.employees WHERE school_id = p_school_id AND id = p_employee_id FOR UPDATE;
                -- Every new payroll row of these employments (result, adjustment, LWF charge) and
                -- every rehire takes FOR KEY SHARE on them: they wait for this unit.
                PERFORM 1 FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id ORDER BY id FOR UPDATE;
                IF NOT EXISTS (SELECT 1 FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id)
                   OR EXISTS (SELECT 1 FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id
                               AND (status NOT IN ('separated', 'terminated', 'retired', 'deceased') OR ends_on IS NULL OR ends_on >= p_cutoff)) THEN
                    RAISE EXCEPTION 'retention: the Employee separated less than the payroll evidence period ago (retention_payroll_employee)';
                END IF;
            END;
            $$;

            CREATE FUNCTION retention_expire_payroll_employee_evidence(p_school_id uuid, p_employee_id uuid, p_cutoff date, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_results uuid[];
                v_runs uuid[];
                v_count integer := 0;
                v_step integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_payroll_employee_floor(p_school_id, p_employee_id, p_cutoff);

                -- Read only after the Employee's locks: nothing of it can change below.
                SELECT coalesce(array_agg(id), '{}') INTO v_records FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id;
                SELECT coalesce(array_agg(id), '{}') INTO v_results FROM public.payroll_run_results WHERE school_id = p_school_id AND employee_id = p_employee_id;
                SELECT coalesce(array_agg(DISTINCT r), '{}') INTO v_runs FROM (
                    SELECT payroll_run_id AS r FROM public.payroll_run_results WHERE school_id = p_school_id AND id = ANY (v_results)
                    UNION SELECT payroll_run_id FROM public.payroll_adjustments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)
                    UNION SELECT rr.payroll_run_id FROM public.payroll_lwf_annual_charges l
                            JOIN public.payroll_run_results rr ON rr.id = l.payroll_run_result_id AND rr.school_id = l.school_id
                           WHERE l.school_id = p_school_id AND l.employment_record_id = ANY (v_records)
                ) s;
                IF cardinality(v_runs) > 2000 THEN
                    RAISE EXCEPTION 'retention: the unit is too large (retention_payroll_unit)';
                END IF;

                -- The lock posting, reversal, statutory posting and a correction insert conflict with.
                PERFORM 1 FROM public.payroll_runs WHERE school_id = p_school_id AND id = ANY (v_runs) ORDER BY id FOR UPDATE;

                IF EXISTS (SELECT 1 FROM public.payroll_runs WHERE school_id = p_school_id AND id = ANY (v_runs)
                            AND (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff::timestamp))
                   OR EXISTS (SELECT 1 FROM public.payroll_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND created_at >= p_cutoff::timestamp)
                   OR EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND created_at >= p_cutoff::timestamp)
                   OR EXISTS (SELECT 1 FROM public.payroll_statutory_calculation_results s
                                JOIN public.payroll_run_results rr ON rr.id = s.payroll_run_result_id AND rr.school_id = s.school_id
                               WHERE s.school_id = p_school_id AND rr.payroll_run_id = ANY (v_runs)
                                 AND NOT EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings p
                                                  WHERE p.school_id = p_school_id AND p.payroll_run_id = rr.payroll_run_id AND p.posting_kind = 'original'))
                   OR EXISTS (SELECT 1 FROM public.payroll_lwf_annual_charges l
                               WHERE l.school_id = p_school_id AND l.payroll_run_result_id = ANY (v_results) AND NOT (l.employment_record_id = ANY (v_records))) THEN
                    RAISE EXCEPTION 'retention: the payroll evidence is not complete or not old enough (retention_payroll_dependency)';
                END IF;

                IF p_dry_run THEN
                    SELECT cardinality(v_results)
                         + (SELECT count(*) FROM public.payroll_adjustments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                         + (SELECT count(*) FROM public.payroll_lwf_annual_charges WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                      INTO v_count;
                    RETURN v_count;
                END IF;

                PERFORM set_config('app.payroll_retention', 'on', true);
                DELETE FROM public.payroll_lwf_annual_charges WHERE school_id = p_school_id AND employment_record_id = ANY (v_records) AND payroll_run_result_id = ANY (v_results);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                -- Lines and statutory results go by their ON DELETE CASCADE.
                DELETE FROM public.payroll_run_results WHERE school_id = p_school_id AND id = ANY (v_results);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.payroll_adjustments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records) AND payroll_run_id = ANY (v_runs);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                UPDATE public.payroll_runs SET results_expired_at = now() AT TIME ZONE 'UTC'
                 WHERE school_id = p_school_id AND id = ANY (v_runs) AND results_expired_at IS NULL;
                PERFORM set_config('app.payroll_retention', '', true);

                IF EXISTS (SELECT 1 FROM public.payroll_run_results WHERE school_id = p_school_id AND employee_id = p_employee_id)
                   OR EXISTS (SELECT 1 FROM public.payroll_adjustments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                   OR EXISTS (SELECT 1 FROM public.payroll_lwf_annual_charges WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)) THEN
                    RAISE EXCEPTION 'retention: payroll evidence remains (retention_payroll_unit)';
                END IF;
                RETURN v_count;
            END;
            $$;

            CREATE FUNCTION retention_expire_payroll_run(p_school_id uuid, p_run_id uuid, p_cutoff timestamp, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_runs uuid[];
                v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '8 years');

                -- The regular run first (a concurrent correction insert holds FOR KEY SHARE
                -- on it), then the group as committed after that lock, in id order.
                PERFORM 1 FROM public.payroll_runs WHERE school_id = p_school_id AND id = p_run_id AND run_kind = 'regular' FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'retention: not a regular payroll run of this School (retention_payroll_dependency)';
                END IF;
                SELECT array_agg(id ORDER BY id) INTO v_runs FROM public.payroll_runs
                 WHERE school_id = p_school_id AND (id = p_run_id OR corrects_payroll_run_id = p_run_id);
                PERFORM 1 FROM public.payroll_runs WHERE school_id = p_school_id AND id = ANY (v_runs) ORDER BY id FOR UPDATE;

                IF EXISTS (SELECT 1 FROM public.payroll_runs WHERE school_id = p_school_id AND id = ANY (v_runs)
                            AND (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff OR results_expired_at IS NULL))
                   OR EXISTS (SELECT 1 FROM public.payroll_run_results WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs))
                   OR EXISTS (SELECT 1 FROM public.payroll_adjustments WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs))
                   OR EXISTS (SELECT 1 FROM public.payroll_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND created_at >= p_cutoff)
                   OR EXISTS (SELECT 1 FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND created_at >= p_cutoff) THEN
                    RAISE EXCEPTION 'retention: the payroll run still holds evidence (retention_payroll_dependency)';
                END IF;

                SELECT (SELECT count(*) FROM public.payroll_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs))
                     + (SELECT count(*) FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs))
                  INTO v_count;
                IF p_dry_run THEN
                    RETURN v_count;
                END IF;

                DELETE FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND posting_kind = 'reversal';
                DELETE FROM public.payroll_statutory_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs);
                DELETE FROM public.payroll_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs) AND posting_kind = 'reversal';
                DELETE FROM public.payroll_run_postings WHERE school_id = p_school_id AND payroll_run_id = ANY (v_runs);
                DELETE FROM public.payroll_runs WHERE school_id = p_school_id AND id = ANY (v_runs) AND run_kind = 'correction';
                DELETE FROM public.payroll_runs WHERE school_id = p_school_id AND id = p_run_id;
                RETURN v_count;
            END;
            $$;
            SQL);

        $this->freezeTriggers(true);

        // The patched freeze triggers call the flag check on every delete,
        // including the runtime role's ordinary draft recalculation; it
        // answers true only inside the definer function (owner privileges).
        DB::statement('REVOKE ALL ON FUNCTION payroll_retention_delete_allowed() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION payroll_retention_delete_allowed() TO school_os_app');
        DB::statement('REVOKE ALL ON FUNCTION payroll_runs_guard_results_expired_at() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION payroll_runs_guard_results_expired_at() TO school_os_app');
        DB::statement('REVOKE ALL ON FUNCTION retention_assert_payroll_employee_floor(uuid, uuid, date) FROM PUBLIC');
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO school_os_app");
        }
    }

    public function down(): void
    {
        if (DB::table('payroll_runs')->whereNotNull('results_expired_at')->exists()) {
            throw new RuntimeException('Refusing to roll back: payroll evidence has been expired (E21.3F). Deleted evidence cannot be restored, and dropping the marker would make an incomplete run look complete.');
        }

        $this->freezeTriggers(false);
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}({$signature})");
        }
        DB::statement('DROP FUNCTION IF EXISTS retention_assert_payroll_employee_floor(uuid, uuid, date)');
        DB::statement('DROP TRIGGER IF EXISTS trg_payroll_runs_guard_results_expired_at ON payroll_runs');
        DB::statement('DROP FUNCTION IF EXISTS payroll_runs_guard_results_expired_at()');
        DB::statement('ALTER TABLE payroll_runs DROP COLUMN results_expired_at');
        DB::statement('DROP FUNCTION IF EXISTS payroll_retention_delete_allowed()');
    }

    /**
     * The three freeze triggers (2026_09_15_090700, 2026_09_15_090800,
     * 2026_10_05_091200): verbatim, with (`$patched`) or without the one
     * retention allowance at the top.
     */
    private function freezeTriggers(bool $patched): void
    {
        $allow = $patched ? "IF TG_OP = 'DELETE' AND public.payroll_retention_delete_allowed() THEN\n                    RETURN OLD;\n                END IF;\n\n                " : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION payroll_run_results_freeze_after_approval() RETURNS trigger AS \$\$
            DECLARE
                parent_status text;
                target_run_id uuid;
            BEGIN
                {$allow}target_run_id := COALESCE(NEW.payroll_run_id, OLD.payroll_run_id);

                SELECT status INTO parent_status FROM payroll_runs WHERE id = target_run_id;

                IF parent_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_run_results: parent run (%) is % -- results are frozen.', target_run_id, parent_status;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION payroll_run_result_lines_freeze_after_approval() RETURNS trigger AS \$\$
            DECLARE
                parent_status text;
                target_result_id uuid;
            BEGIN
                {$allow}target_result_id := COALESCE(NEW.payroll_run_result_id, OLD.payroll_run_result_id);

                SELECT pr.status INTO parent_status
                FROM payroll_run_results rr
                JOIN payroll_runs pr ON pr.id = rr.payroll_run_id
                WHERE rr.id = target_result_id;

                IF parent_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_run_result_lines: parent run is % -- lines are frozen (result %).', parent_status, target_result_id;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION payroll_statutory_results_freeze_after_approval() RETURNS trigger AS \$\$
            DECLARE
                parent_run_status text;
                target_result_id uuid;
            BEGIN
                {$allow}target_result_id := COALESCE(NEW.payroll_run_result_id, OLD.payroll_run_result_id);

                SELECT pr.status INTO parent_run_status
                FROM payroll_run_results prr
                JOIN payroll_runs pr ON pr.id = prr.payroll_run_id
                WHERE prr.id = target_result_id;

                IF parent_run_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_statutory_calculation_results: parent run result (%) is % -- statutory results are frozen.', target_result_id, parent_run_status;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            \$\$ LANGUAGE plpgsql;
            SQL);
    }
};
