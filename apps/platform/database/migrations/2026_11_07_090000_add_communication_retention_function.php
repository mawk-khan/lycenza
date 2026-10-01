<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.2C (E21-D3, project-adopted, pending legal ratification): the one
 * Communications table the runtime role cannot delete from,
 * `communication_delivery_policy_decisions` (append-only), gets a narrow
 * retention function in the E21.2B pattern
 * (2026_11_06_090000_add_retention_expiry_functions):
 * - SECURITY DEFINER, `search_path` pinned;
 * - fixed table and predicate (`created_at`, the moment the decision was
 *   final);
 * - a database age floor of one year;
 * - a tenant tie;
 * - a batch capped at 5000, SKIP LOCKED;
 * - a dry-run count.
 * EXECUTE goes to the runtime role only. The other Communications tables
 * are ordinary runtime-deletable tables; their retention is the
 * maintenance command's explicit, predicate-bound work
 * (CommunicationRetentionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION retention_expire_communication_delivery_policy_decisions(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '1 year');
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.communication_delivery_policy_decisions WHERE school_id = p_school_id AND created_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.communication_delivery_policy_decisions d
                 WHERE d.id IN (SELECT id FROM public.communication_delivery_policy_decisions
                                 WHERE school_id = p_school_id AND created_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND d.school_id = p_school_id AND d.created_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION retention_expire_communication_delivery_policy_decisions(uuid, timestamp, integer, boolean) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION retention_expire_communication_delivery_policy_decisions(uuid, timestamp, integer, boolean) TO school_os_app');
    }

    /** Drops only the function; no retained row is touched. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS retention_expire_communication_delivery_policy_decisions(uuid, timestamp, integer, boolean)');
    }
};
