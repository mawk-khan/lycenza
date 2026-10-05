<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.6 (ADR 0066 §14, finding M1): the runtime role can no longer
 * manufacture retention eligibility.
 *
 * Employment records and Student enrollments (the D9 separation and the D7
 * exit):
 * - The legitimate workflows end a record ONCE, together with its move out
 *   of `active`, with any date -- including a past one (a separation or a
 *   completion recorded after the fact). A database cannot tell a genuine
 *   backdated end from a forged one by its value.
 * - So the database records WHEN the end was recorded:
 *   `ended_recorded_at`, set from the database clock by the guard trigger
 *   when `ends_on` is first set, never caller-supplied, never changed.
 * - Retention counts from the LATER of `ends_on` and that date (owner
 *   decision, 2026-10-05). It only ever retains longer.
 * - Once ended, `ends_on` and `status` never change, and an end is never
 *   reopened (`retention_eligibility_guard`).
 * - The identity columns and `starts_on` are fixed.
 * - The D9 floor (`retention_assert_payroll_employee_floor`, shared by
 *   Payroll and HRX) and the D7 core floor
 *   (`retention_assert_student_core_floor`) additionally require every end
 *   to have been recorded before the cutoff.
 * - Existing ended rows are backfilled with their best record of when they
 *   ended: `updated_at`, else `created_at`.
 *
 * Erasure cases (D10):
 * - The workflow (ErasureCaseService) only moves `requested ->
 *   approved | partially_approved | denied`, `approved | partially_approved
 *   -> executing -> completed`, and repeats `executing` / `completed`.
 * - The guard allows exactly that.
 * - It sets `requested_at`, `decided_at`, `execution_started_at` and
 *   `completed_at` from the database clock, once each, and fixes every
 *   other column after its step.
 * - A decided or closed case can therefore never be backdated or reopened
 *   to reach `retention_expire_erasure_cases` early.
 *
 * Lifecycle markers (G1, Admissions): the Guardian `no_relationship_since`
 * and Admissions `terminal_at` guards let a past-dated "backfill" set a
 * missing marker -- and the runtime role holds UPDATE, so it could age a
 * Guardian or a legacy terminal application into eligibility. That branch
 * is now the schema owner's only; the operator command
 * (`platform:lifecycle-markers-backfill`, from the immutable audit ledger)
 * runs on the maintenance connection.
 *
 * The schema owner (migrations, operator data repair) is exempt from these
 * guards, as it owns the tables anyway.
 *
 * Rollback drops the guards, restores both floor helpers byte for byte and
 * drops the column. It removes a protection rather than adding a privilege,
 * so it is reversible.
 */
return new class extends Migration
{
    private const TERMINAL_EMPLOYMENT = "'separated', 'terminated', 'retired', 'deceased'";

    private const PAYROLL_FLOOR_FROM = "AND (status NOT IN ('separated', 'terminated', 'retired', 'deceased') OR ends_on IS NULL OR ends_on >= p_cutoff)) THEN";

    private const PAYROLL_FLOOR_TO = "AND (status NOT IN ('separated', 'terminated', 'retired', 'deceased') OR ends_on IS NULL OR ends_on >= p_cutoff\n                        OR ended_recorded_at IS NULL OR ended_recorded_at >= p_cutoff::timestamp)) THEN";

    private const STUDENT_FLOOR_FROM = "AND (status = 'active' OR ends_on IS NULL OR ends_on >= p_cutoff)) THEN";

    private const STUDENT_FLOOR_TO = "AND (status = 'active' OR ends_on IS NULL OR ends_on >= p_cutoff\n                        OR ended_recorded_at IS NULL OR ended_recorded_at >= p_cutoff::timestamp)) THEN";

    /** The Guardian marker's past-dated backfill: owner-only since E21-RH.6 (the operator command runs on the maintenance connection). */
    private const GUARDIAN_BACKFILL_FROM = '       AND NOT EXISTS (SELECT 1 FROM public.student_guardian_relationships WHERE guardian_id = NEW.id) THEN';

    private const GUARDIAN_BACKFILL_TO = "       AND NOT EXISTS (SELECT 1 FROM public.student_guardian_relationships WHERE guardian_id = NEW.id)\n       AND pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN";

    /** The Admissions decision marker's past-dated backfill: owner-only since E21-RH.6. */
    private const ADMISSIONS_BACKFILL_FROM = "    ELSIF OLD.terminal_at IS NULL AND NEW.terminal_at IS NOT NULL\n          AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.terminal_at > v_now) THEN";

    private const ADMISSIONS_BACKFILL_TO = "    ELSIF OLD.terminal_at IS NULL AND NEW.terminal_at IS NOT NULL\n          AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.terminal_at > v_now\n               OR NOT pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER')) THEN";

    public function up(): void
    {
        DB::statement('ALTER TABLE employment_records ADD COLUMN ended_recorded_at timestamp NULL');
        DB::statement('ALTER TABLE student_enrollments ADD COLUMN ended_recorded_at timestamp NULL');
        // Forced RLS applies to the owner too: one School context at a time.
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::transaction(function () use ($schoolId) {
                DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
                foreach (['employment_records', 'student_enrollments'] as $table) {
                    DB::update("UPDATE {$table} SET ended_recorded_at = coalesce(updated_at, created_at, now() AT TIME ZONE 'UTC') WHERE school_id = ? AND ends_on IS NOT NULL", [$schoolId]);
                }
            });
        }

        $terminal = self::TERMINAL_EMPLOYMENT;
        DB::unprepared(<<<SQL
            CREATE FUNCTION retention_guard_end_record() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_parent text := TG_ARGV[0];
                v_terminal boolean;
            BEGIN
                -- The schema owner (migrations, operator repair) is exempt: it owns the table.
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    NEW.ended_recorded_at := CASE WHEN NEW.ends_on IS NULL THEN NULL ELSE pg_catalog.now() AT TIME ZONE 'UTC' END;
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                   OR (v_parent = 'employee' AND to_jsonb(NEW)->>'employee_id' IS DISTINCT FROM to_jsonb(OLD)->>'employee_id')
                   OR (v_parent = 'student' AND to_jsonb(NEW)->>'student_id' IS DISTINCT FROM to_jsonb(OLD)->>'student_id') THEN
                    RAISE EXCEPTION '%: the identity and start of a record are fixed (retention_eligibility_guard)', TG_TABLE_NAME;
                END IF;

                v_terminal := CASE WHEN v_parent = 'employee' THEN NEW.status IN ({$terminal}) ELSE NEW.status <> 'active' END;
                IF OLD.ends_on IS NOT NULL THEN
                    IF NEW.ends_on IS DISTINCT FROM OLD.ends_on OR NEW.status IS DISTINCT FROM OLD.status THEN
                        RAISE EXCEPTION '%: an ended record keeps its end and status (retention_eligibility_guard)', TG_TABLE_NAME;
                    END IF;
                ELSIF NEW.ends_on IS NOT NULL THEN
                    IF NOT v_terminal THEN
                        RAISE EXCEPTION '%: a record ends only with a terminal status (retention_eligibility_guard)', TG_TABLE_NAME;
                    END IF;
                ELSIF v_parent = 'employee' AND OLD.status IN ({$terminal}) AND NOT v_terminal THEN
                    RAISE EXCEPTION '%: a terminal record is never reopened (retention_eligibility_guard)', TG_TABLE_NAME;
                END IF;

                NEW.ended_recorded_at := CASE WHEN OLD.ends_on IS NULL AND NEW.ends_on IS NOT NULL THEN pg_catalog.now() AT TIME ZONE 'UTC' ELSE OLD.ended_recorded_at END;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_guard_end_record() FROM PUBLIC;
            CREATE TRIGGER trg_employment_records_eligibility_guard BEFORE INSERT OR UPDATE ON employment_records
                FOR EACH ROW EXECUTE FUNCTION retention_guard_end_record('employee');
            CREATE TRIGGER trg_student_enrollments_eligibility_guard BEFORE INSERT OR UPDATE ON student_enrollments
                FOR EACH ROW EXECUTE FUNCTION retention_guard_end_record('student');

            CREATE FUNCTION erasure_cases_guard_transition() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_now timestamp := pg_catalog.now() AT TIME ZONE 'UTC';
            BEGIN
                IF pg_catalog.pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = TG_RELID), 'MEMBER') THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'requested' OR NEW.decided_at IS NOT NULL OR NEW.decision_reason IS NOT NULL OR NEW.target_on IS NOT NULL
                       OR NEW.execution_started_at IS NOT NULL OR NEW.completed_at IS NOT NULL OR NEW.outcome IS NOT NULL THEN
                        RAISE EXCEPTION 'erasure_cases: a case is opened as requested (retention_eligibility_guard)';
                    END IF;
                    NEW.requested_at := v_now;
                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.scope IS DISTINCT FROM OLD.scope OR NEW.school_id IS DISTINCT FROM OLD.school_id
                   OR NEW.subject_type IS DISTINCT FROM OLD.subject_type OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
                   OR NEW.request_channel IS DISTINCT FROM OLD.request_channel OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                    RAISE EXCEPTION 'erasure_cases: the request is fixed (retention_eligibility_guard)';
                END IF;

                IF OLD.status = 'requested' AND NEW.status IN ('approved', 'partially_approved', 'denied') THEN
                    IF NEW.execution_started_at IS NOT NULL OR NEW.completed_at IS NOT NULL OR NEW.outcome IS NOT NULL THEN
                        RAISE EXCEPTION 'erasure_cases: a decision only decides (retention_eligibility_guard)';
                    END IF;
                    NEW.decided_at := v_now;
                    RETURN NEW;
                END IF;

                -- After the decision: decided_at, decision_reason and target_on never change.
                IF NEW.decided_at IS DISTINCT FROM OLD.decided_at OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason
                   OR NEW.target_on IS DISTINCT FROM OLD.target_on THEN
                    RAISE EXCEPTION 'erasure_cases: a decision is fixed (retention_eligibility_guard)';
                END IF;

                IF OLD.status IN ('approved', 'partially_approved') AND NEW.status = 'executing' THEN
                    IF NEW.completed_at IS NOT NULL OR NEW.outcome IS DISTINCT FROM OLD.outcome THEN
                        RAISE EXCEPTION 'erasure_cases: starting execution changes nothing else (retention_eligibility_guard)';
                    END IF;
                    NEW.execution_started_at := coalesce(OLD.execution_started_at, v_now);
                ELSIF OLD.status = 'executing' AND NEW.status = 'executing' THEN
                    IF NEW.execution_started_at IS DISTINCT FROM OLD.execution_started_at OR NEW.completed_at IS NOT NULL
                       OR NEW.outcome IS DISTINCT FROM OLD.outcome THEN
                        RAISE EXCEPTION 'erasure_cases: a running execution changes nothing (retention_eligibility_guard)';
                    END IF;
                ELSIF OLD.status = 'executing' AND NEW.status = 'completed' THEN
                    IF NEW.execution_started_at IS DISTINCT FROM OLD.execution_started_at THEN
                        RAISE EXCEPTION 'erasure_cases: the execution start is fixed (retention_eligibility_guard)';
                    END IF;
                    NEW.completed_at := v_now;
                ELSIF OLD.status = 'completed' AND NEW.status = 'completed' THEN
                    IF NEW.execution_started_at IS DISTINCT FROM OLD.execution_started_at OR NEW.completed_at IS DISTINCT FROM OLD.completed_at THEN
                        RAISE EXCEPTION 'erasure_cases: a completed case keeps its dates (retention_eligibility_guard)';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'erasure_cases: % -> % is not a case transition (retention_eligibility_guard)', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION erasure_cases_guard_transition() FROM PUBLIC;
            CREATE TRIGGER trg_erasure_cases_guard_transition BEFORE INSERT OR UPDATE ON erasure_cases
                FOR EACH ROW EXECUTE FUNCTION erasure_cases_guard_transition();
            SQL);

        $this->replaceInFunction('retention_assert_payroll_employee_floor', self::PAYROLL_FLOOR_FROM, self::PAYROLL_FLOOR_TO);
        $this->replaceInFunction('retention_assert_student_core_floor', self::STUDENT_FLOOR_FROM, self::STUDENT_FLOOR_TO);
        $this->replaceInFunction('guardians_guard_no_relationship_since', self::GUARDIAN_BACKFILL_FROM, self::GUARDIAN_BACKFILL_TO);
        $this->replaceInFunction('admission_applications_guard_terminal_at', self::ADMISSIONS_BACKFILL_FROM, self::ADMISSIONS_BACKFILL_TO);
    }

    public function down(): void
    {
        $this->replaceInFunction('admission_applications_guard_terminal_at', self::ADMISSIONS_BACKFILL_TO, self::ADMISSIONS_BACKFILL_FROM);
        $this->replaceInFunction('guardians_guard_no_relationship_since', self::GUARDIAN_BACKFILL_TO, self::GUARDIAN_BACKFILL_FROM);
        $this->replaceInFunction('retention_assert_student_core_floor', self::STUDENT_FLOOR_TO, self::STUDENT_FLOOR_FROM);
        $this->replaceInFunction('retention_assert_payroll_employee_floor', self::PAYROLL_FLOOR_TO, self::PAYROLL_FLOOR_FROM);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_erasure_cases_guard_transition ON erasure_cases;
            DROP FUNCTION erasure_cases_guard_transition();
            DROP TRIGGER trg_student_enrollments_eligibility_guard ON student_enrollments;
            DROP TRIGGER trg_employment_records_eligibility_guard ON employment_records;
            DROP FUNCTION retention_guard_end_record();
            ALTER TABLE student_enrollments DROP COLUMN ended_recorded_at;
            ALTER TABLE employment_records DROP COLUMN ended_recorded_at;
            SQL);
    }

    private function replaceInFunction(string $function, string $from, string $to): void
    {
        $definition = (string) DB::selectOne(
            "SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->d;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException("{$function}: unexpected body shape; refusing to change its floor.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }
};
