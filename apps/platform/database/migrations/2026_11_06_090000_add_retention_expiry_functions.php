<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.2B (docs/security/E21-RETENTION-DETERMINATION.md, E21-D1/D2/D6,
 * project-adopted, pending legal ratification): the narrow privileged
 * retention path for protected ledgers.
 *
 * The runtime role (`school_os_app`) keeps NO DELETE on any of these tables
 * (DatabaseRoleVerifier::NO_RUNTIME_DELETE). Instead each table gets one
 * fixed retention function:
 * - SECURITY DEFINER, owned by the migration role, `search_path` pinned
 *   and every table schema-qualified;
 * - one table, one fixed eligibility predicate, no caller-supplied SQL,
 *   table or column;
 * - an AGE FLOOR in the database: the caller's cutoff may never be later
 *   than now() minus the adopted period, so no caller can expire a row
 *   early (a longer configured period is allowed);
 * - School-scoped functions require the School to be the caller's own
 *   tenant context (`app.current_school_id`), so one School's maintenance
 *   run can never delete another School's rows;
 * - a batch capped at 5000 rows, deterministic `ORDER BY id`, rows locked
 *   `SKIP LOCKED`, the predicate re-applied in the DELETE;
 * - `p_dry_run` counts the same predicate without deleting.
 *
 * EXECUTE is revoked from PUBLIC and granted to the runtime role only.
 * Only the retention console commands call these functions (architecture
 * guard).
 *
 * The longest-applicable-period rule is enforced by the database itself:
 * an elevation still referenced by a School audit event, or a Group grant
 * still referenced by an elevation, is skipped (the FKs are RESTRICT).
 */
return new class extends Migration
{
    /** @var array<string, string> function => signature (for DROP/GRANT) */
    private const FUNCTIONS = [
        'retention_expire_school_audit_events' => 'uuid, timestamp, integer, boolean',
        'retention_expire_platform_audit_events' => 'timestamp, integer, boolean',
        'retention_expire_released_email_suppressions' => 'timestamp, integer, boolean',
        'retention_expire_membership_role_assignments' => 'uuid, timestamp, integer, boolean',
        'retention_expire_teaching_assignments' => 'uuid, date, integer, boolean',
        'retention_expire_school_elevations' => 'uuid, timestamp, integer, boolean',
        'retention_expire_group_role_assignments' => 'timestamp, integer, boolean',
        'retention_expire_platform_role_assignments' => 'timestamp, integer, boolean',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION retention_assert_floor(p_cutoff timestamp, p_period interval) RETURNS void
                LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_cutoff IS NULL OR p_cutoff > (now() AT TIME ZONE 'UTC') - p_period THEN
                    RAISE EXCEPTION 'retention: cutoff % is younger than the adopted period % (retention_floor)', p_cutoff, p_period;
                END IF;
            END;
            $$;

            CREATE FUNCTION retention_assert_tenant(p_school_id uuid) RETURNS void
                LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF p_school_id IS NULL OR p_school_id IS DISTINCT FROM NULLIF(current_setting('app.current_school_id', true), '')::uuid THEN
                    RAISE EXCEPTION 'retention: the School must be the current tenant context (retention_tenant)';
                END IF;
            END;
            $$;

            -- E21-D1: School audit, 7 years from occurred_at.
            CREATE FUNCTION retention_expire_school_audit_events(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.school_audit_events WHERE school_id = p_school_id AND occurred_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.school_audit_events e
                 WHERE e.id IN (SELECT id FROM public.school_audit_events
                                 WHERE school_id = p_school_id AND occurred_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND e.school_id = p_school_id AND e.occurred_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D1: platform audit (no School), 7 years from occurred_at.
            CREATE FUNCTION retention_expire_platform_audit_events(p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.platform_audit_events WHERE occurred_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.platform_audit_events e
                 WHERE e.id IN (SELECT id FROM public.platform_audit_events WHERE occurred_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND e.occurred_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D2: RELEASED suppressions, 1 year after released_at. An
            -- active suppression (released_at NULL) is never eligible.
            CREATE FUNCTION retention_expire_released_email_suppressions(p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_floor(p_cutoff, interval '1 year');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.email_suppressions WHERE released_at IS NOT NULL AND released_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.email_suppressions s
                 WHERE s.id IN (SELECT id FROM public.email_suppressions WHERE released_at IS NOT NULL AND released_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND s.released_at IS NOT NULL AND s.released_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D6: revoked School role grants, 7 years after revoked_at.
            CREATE FUNCTION retention_expire_membership_role_assignments(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.membership_role_assignments WHERE school_id = p_school_id AND revoked_at IS NOT NULL AND revoked_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.membership_role_assignments g
                 WHERE g.id IN (SELECT id FROM public.membership_role_assignments
                                 WHERE school_id = p_school_id AND revoked_at IS NOT NULL AND revoked_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND g.school_id = p_school_id AND g.revoked_at IS NOT NULL AND g.revoked_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D6: TeachingAssignments whose authority ended (ends_on, the
            -- last effective day) more than 7 years ago. Open (ends_on NULL)
            -- and future rows are never eligible. The cutoff is a School-local
            -- date, which may run up to one day ahead of UTC, hence the floor
            -- on the UTC date plus one day.
            CREATE FUNCTION retention_expire_teaching_assignments(p_school_id uuid, p_cutoff date, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                IF p_cutoff IS NULL OR p_cutoff > (((now() AT TIME ZONE 'UTC') - interval '7 years')::date + 1) THEN
                    RAISE EXCEPTION 'retention: cutoff % is younger than the adopted period (retention_floor)', p_cutoff;
                END IF;
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.teaching_assignments WHERE school_id = p_school_id AND ends_on IS NOT NULL AND ends_on < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.teaching_assignments t
                 WHERE t.id IN (SELECT id FROM public.teaching_assignments
                                 WHERE school_id = p_school_id AND ends_on IS NOT NULL AND ends_on < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND t.school_id = p_school_id AND t.ends_on IS NOT NULL AND t.ends_on < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D6: finished elevations, 7 years after they ended (ended_at,
            -- else expires_at), and only once no School audit event still
            -- references them (longest period wins).
            CREATE FUNCTION retention_expire_school_elevations(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.school_elevations el
                     WHERE el.school_id = p_school_id AND el.status <> 'active' AND COALESCE(el.ended_at, el.expires_at) < p_cutoff
                       AND NOT EXISTS (SELECT 1 FROM public.school_audit_events a WHERE a.elevation_id = el.id);
                    RETURN v_count;
                END IF;
                DELETE FROM public.school_elevations el
                 WHERE el.id IN (SELECT x.id FROM public.school_elevations x
                                  WHERE x.school_id = p_school_id AND x.status <> 'active' AND COALESCE(x.ended_at, x.expires_at) < p_cutoff
                                    AND NOT EXISTS (SELECT 1 FROM public.school_audit_events a WHERE a.elevation_id = x.id)
                                  ORDER BY x.id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND el.school_id = p_school_id AND el.status <> 'active' AND COALESCE(el.ended_at, el.expires_at) < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D6: revoked Group grants, 7 years after revoked_at, once no
            -- elevation still references them.
            CREATE FUNCTION retention_expire_group_role_assignments(p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.group_role_assignments g
                     WHERE g.revoked_at IS NOT NULL AND g.revoked_at < p_cutoff
                       AND NOT EXISTS (SELECT 1 FROM public.school_elevations el WHERE el.group_role_assignment_id = g.id);
                    RETURN v_count;
                END IF;
                DELETE FROM public.group_role_assignments g
                 WHERE g.id IN (SELECT x.id FROM public.group_role_assignments x
                                 WHERE x.revoked_at IS NOT NULL AND x.revoked_at < p_cutoff
                                   AND NOT EXISTS (SELECT 1 FROM public.school_elevations el WHERE el.group_role_assignment_id = x.id)
                                 ORDER BY x.id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND g.revoked_at IS NOT NULL AND g.revoked_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;

            -- E21-D6: revoked platform role grants, 7 years after revoked_at.
            CREATE FUNCTION retention_expire_platform_role_assignments(p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.platform_role_assignments WHERE revoked_at IS NOT NULL AND revoked_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.platform_role_assignments g
                 WHERE g.id IN (SELECT id FROM public.platform_role_assignments WHERE revoked_at IS NOT NULL AND revoked_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND g.revoked_at IS NOT NULL AND g.revoked_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION retention_assert_floor(timestamp, interval) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION retention_assert_tenant(uuid) FROM PUBLIC');

        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO school_os_app");
        }
    }

    /**
     * Drops only the functions. No retained row is touched: rolling back
     * removes the expiry path, never history.
     */
    public function down(): void
    {
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}({$signature})");
        }

        DB::statement('DROP FUNCTION IF EXISTS retention_assert_tenant(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS retention_assert_floor(timestamp, interval)');
    }
};
