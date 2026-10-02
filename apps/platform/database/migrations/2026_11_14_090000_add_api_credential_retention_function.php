<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.3E (E21.2G I3, E21-D6, project-adopted, pending legal ratification):
 * an ENDED API client credential is authority history, kept 7 calendar
 * years after its authority ended, then deleted.
 *
 * The runtime role has no DELETE on `api_client_credentials`
 * (DatabaseRoleVerifier::NO_RUNTIME_DELETE), so it gets one fixed retention
 * function in the E21.2B pattern: SECURITY DEFINER, pinned `search_path`,
 * one table and one fixed predicate, a 7-year database floor, the School
 * as the caller's tenant context (the table is School-scoped but not RLS,
 * like `school_elevations`), a batch capped at 5000, `SKIP LOCKED`, the
 * predicate re-applied, and a dry-run count. EXECUTE goes to the runtime
 * role only.
 *
 * Authority end: a credential authenticates only before `expires_at` and
 * while unrevoked, a revocation is final, and an expiry can only be
 * shortened (`assert_api_client_credential_governance`), so its authority
 * ended at LEAST(revoked_at, expires_at); a supersession only shortens the
 * expiry. A current credential (unrevoked, not yet expired) can never be
 * eligible. The secret is only ever stored as a hash and is unusable from
 * that end; this bounds how long the history stays. `last_used_at` is
 * never the trigger. API clients (configuration) are untouched.
 *
 * Rollback drops the function only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION retention_expire_api_client_credentials(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '7 years');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.api_client_credentials
                     WHERE school_id = p_school_id AND LEAST(revoked_at, expires_at) < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.api_client_credentials c
                 WHERE c.id IN (SELECT id FROM public.api_client_credentials
                                 WHERE school_id = p_school_id AND LEAST(revoked_at, expires_at) < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND c.school_id = p_school_id AND LEAST(c.revoked_at, c.expires_at) < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION retention_expire_api_client_credentials(uuid, timestamp, integer, boolean) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION retention_expire_api_client_credentials(uuid, timestamp, integer, boolean) TO school_os_app');
    }

    /** Drops only the function; no retained row is touched. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS retention_expire_api_client_credentials(uuid, timestamp, integer, boolean)');
    }
};
