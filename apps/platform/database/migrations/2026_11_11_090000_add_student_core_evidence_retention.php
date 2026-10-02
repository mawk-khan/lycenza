<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.3B (E21-D7 + E21.2G P1/C4, project-adopted, pending legal
 * ratification): the narrow privileged path for the two append-only
 * Student-core evidence tables. Both are kept with the Student core
 * record (25 calendar years after final exit) and leave only with it,
 * inside the core purge's one-Student transaction.
 *
 * - `student_processing_authorizations` (Students): the runtime role keeps
 *   its UPDATE/DELETE privileges (row locks need them), but a trigger
 *   refuses every direct DELETE. The trigger now also allows a delete
 *   inside the function below: the transaction-local
 *   `app.student_core_retention` flag AND the table owner's privileges,
 *   which the runtime role never has (the E21.3A2 guard pattern).
 * - `communication_domain_consent_events` (Communications): append-only by
 *   privilege (no runtime DELETE). Only the definer function deletes.
 *
 * Each function (E21.2B pattern) is SECURITY DEFINER with a pinned
 * `search_path`, one table and one fixed predicate (ONE Student's rows),
 * EXECUTE for the runtime role only, never PUBLIC, and called only
 * through `RetentionExpiry`. The DATABASE re-proves the Student core
 * floor itself, never trusting the caller's eligibility:
 * - the School is the caller's tenant context;
 * - the Student row is locked FOR UPDATE (the purge holds it already);
 * - the cutoff is at least 25 calendar years before the School-local
 *   date (at most one day ahead of UTC);
 * - the Student is `inactive`, has no active Subject Enrollment, at least
 *   one non-cancelled placement, and every non-cancelled placement ended
 *   (no open or active one) before the cutoff.
 * That floor is a necessary condition only. The definition of the final
 * exit stays StudentRetentionEligibility's; the database merely refuses
 * anything younger than the adopted core period.
 *
 * Rollback removes only the mechanism and restores the original trigger.
 * Deleted evidence is not restored (none can be); no retained row is
 * touched.
 */
return new class extends Migration
{
    /** @var array<string, string> function => signature */
    private const FUNCTIONS = [
        'retention_expire_student_processing_authorizations' => 'uuid, uuid, date, boolean',
        'retention_expire_student_consent_events' => 'uuid, uuid, date, boolean',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- True only inside retention_expire_student_processing_authorizations:
            -- its transaction-local flag, AND the table owner's privileges (the
            -- definer's), which the runtime role never has.
            CREATE FUNCTION student_core_retention_delete_allowed() RETURNS boolean
                LANGUAGE sql STABLE SET search_path = pg_catalog, pg_temp AS $$
                SELECT coalesce(current_setting('app.student_core_retention', true), '') = 'on'
                   AND pg_has_role(current_user, (SELECT relowner FROM pg_catalog.pg_class WHERE oid = 'public.student_processing_authorizations'::regclass), 'MEMBER')
            $$;

            -- The Student core floor (not executable by the runtime role).
            CREATE FUNCTION retention_assert_student_core_floor(p_school_id uuid, p_student_id uuid, p_cutoff date) RETURNS void
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_cutoff IS NULL OR p_cutoff > ((((now() AT TIME ZONE 'UTC')::date + 1) - interval '25 years')::date) THEN
                    RAISE EXCEPTION 'retention: cutoff % is younger than the Student core period (retention_floor)', p_cutoff;
                END IF;
                PERFORM 1 FROM public.students WHERE school_id = p_school_id AND id = p_student_id FOR UPDATE;
                IF NOT EXISTS (SELECT 1 FROM public.students WHERE school_id = p_school_id AND id = p_student_id AND status = 'inactive')
                   OR EXISTS (SELECT 1 FROM public.student_subject_enrollments WHERE school_id = p_school_id AND student_id = p_student_id AND status = 'active')
                   OR NOT EXISTS (SELECT 1 FROM public.student_enrollments WHERE school_id = p_school_id AND student_id = p_student_id AND status <> 'cancelled')
                   OR EXISTS (SELECT 1 FROM public.student_enrollments WHERE school_id = p_school_id AND student_id = p_student_id AND status <> 'cancelled'
                               AND (status = 'active' OR ends_on IS NULL OR ends_on >= p_cutoff)) THEN
                    RAISE EXCEPTION 'retention: the Student left less than the core period ago (retention_student_core)';
                END IF;
            END;
            $$;

            -- P1: a Student's processing authorizations, with the core record.
            CREATE FUNCTION retention_expire_student_processing_authorizations(p_school_id uuid, p_student_id uuid, p_cutoff date, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_count integer := 0;
                v_step integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_student_core_floor(p_school_id, p_student_id, p_cutoff);
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.student_processing_authorizations WHERE school_id = p_school_id AND student_id = p_student_id;
                    RETURN v_count;
                END IF;

                PERFORM set_config('app.student_core_retention', 'on', true);
                -- Terminal events point at the grant they end (RESTRICT): leaves first.
                LOOP
                    DELETE FROM public.student_processing_authorizations a
                     WHERE a.school_id = p_school_id AND a.student_id = p_student_id
                       AND NOT EXISTS (SELECT 1 FROM public.student_processing_authorizations t
                                        WHERE t.school_id = a.school_id AND t.terminates_authorization_id = a.id);
                    GET DIAGNOSTICS v_step = ROW_COUNT;
                    v_count := v_count + v_step;
                    EXIT WHEN v_step = 0;
                END LOOP;
                PERFORM set_config('app.student_core_retention', '', true);

                IF EXISTS (SELECT 1 FROM public.student_processing_authorizations WHERE school_id = p_school_id AND student_id = p_student_id) THEN
                    RAISE EXCEPTION 'retention: processing authorizations remain (retention_student_core)';
                END IF;
                RETURN v_count;
            END;
            $$;

            -- C4: a Student's consent events, with the core record.
            CREATE FUNCTION retention_expire_student_consent_events(p_school_id uuid, p_student_id uuid, p_cutoff date, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_student_core_floor(p_school_id, p_student_id, p_cutoff);
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.communication_domain_consent_events WHERE school_id = p_school_id AND student_id = p_student_id;
                    RETURN v_count;
                END IF;
                DELETE FROM public.communication_domain_consent_events WHERE school_id = p_school_id AND student_id = p_student_id;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            CREATE OR REPLACE FUNCTION student_processing_authorizations_reject_direct_delete()
            RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 AND NOT public.student_core_retention_delete_allowed() THEN
                    RAISE EXCEPTION 'student_processing_authorizations is append-only: direct DELETE is not permitted (cascade delete from an owning School remains allowed)';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        // The patched trigger calls the flag check on every delete attempt,
        // including the runtime role's refused ones; it answers true only
        // inside the definer function (owner privileges).
        DB::statement('REVOKE ALL ON FUNCTION student_core_retention_delete_allowed() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION student_core_retention_delete_allowed() TO school_os_app');
        DB::statement('REVOKE ALL ON FUNCTION retention_assert_student_core_floor(uuid, uuid, date) FROM PUBLIC');

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO school_os_app");
        }
    }

    public function down(): void
    {
        // The original guard (2026_10_12_090100), verbatim.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION student_processing_authorizations_reject_direct_delete()
            RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'student_processing_authorizations is append-only: direct DELETE is not permitted (cascade delete from an owning School remains allowed)';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}({$signature})");
        }
        DB::statement('DROP FUNCTION IF EXISTS retention_assert_student_core_floor(uuid, uuid, date)');
        DB::statement('DROP FUNCTION IF EXISTS student_core_retention_delete_allowed()');
    }
};
