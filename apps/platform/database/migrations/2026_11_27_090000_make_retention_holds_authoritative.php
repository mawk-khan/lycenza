<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.3 (ADR 0066 §6, §11): PostgreSQL hold state becomes AUTHORITATIVE
 * for destructive retention, for School and platform holds alike.
 *
 * `retention_holds` replaces the RH.2 transitional mirror
 * (`retention_school_holds`, config-sourced, presence = held):
 * - one row per hold; `scope` `school` (with `school_id`) or `platform`
 *   (without), database-checked;
 * - at most ONE ACTIVE hold per School and one active platform hold
 *   (partial unique indexes);
 * - history is preserved: a release sets `released_at` / `released_by_login`
 *   / `release_reason_code` once; nothing else ever changes, and no row is
 *   ever deleted or truncated (trigger-enforced for every role);
 * - attribution: `placed_by_login` / `released_by_login` are set by the
 *   database from `session_user` (the authenticated maintenance login,
 *   never caller-supplied); `placed_via` says which controlled path placed
 *   it; `reference` / `release_reference` carry the operator's change
 *   reference (a constrained token, never free text). A shared maintenance
 *   login does not identify a human: the reference does.
 *
 * Functions (search_path pinned, every object qualified, never PUBLIC):
 * - `retention_hold_place(scope, school, reason, reference, via)` and
 *   `retention_hold_release(scope, school, reason, reference)`: the only
 *   sanctioned writers, used by the operator commands on the
 *   migration/owner connection; owner-executable only. They take the
 *   hold lock EXCLUSIVELY.
 * - `retention_assert_not_held(school)`: called inside the destructive
 *   definer functions (HRX now). Takes the hold lock SHARED, then refuses
 *   (`retention_hold`) while an active PLATFORM hold exists, or an active
 *   hold of that School. Not executable by the runtime or retention role.
 *   Serialization: a placement or release waits for every destructive
 *   transaction that already passed the check; a destructive transaction
 *   that checks after a placement commits sees it. A missing or unreadable
 *   relation raises, so nothing is deleted (fail closed).
 * - `retention_hold_active_scopes()`: SECURITY DEFINER, read-only; the
 *   active (scope, school_id) pairs only -- no history, no attribution.
 *   EXECUTE for the retention identity only (its PHP-side hold reading).
 *
 * The runtime role has no privilege on any of it. The retention identity
 * has EXECUTE on `retention_hold_active_scopes()` only: it can neither place
 * nor release a hold, nor read history.
 *
 * Data: every row of the old mirror is an active School hold (presence =
 * held), so each becomes an active School hold (`placed_via` `migration`,
 * reason `configuration_transition`, its `held_since`). The migration
 * verifies every migrated School is actively held before dropping the old
 * table. HRX's prologue now calls `retention_assert_not_held()`.
 *
 * Rollback never releases a hold: it refuses while any platform hold or any
 * release history exists (the old mirror cannot represent them); otherwise
 * it restores the mirror with exactly the active School holds and the RH.2
 * prologue byte for byte.
 */
return new class extends Migration
{
    private const PLACE_REASONS = "'litigation', 'regulatory_inquiry', 'audit', 'investigation', 'configuration_transition', 'other'";

    private const RELEASE_REASONS = "'matter_concluded', 'inquiry_closed', 'audit_closed', 'placed_in_error', 'other'";

    private const REFERENCE = "'^[A-Za-z0-9._:/-]{1,64}$'";

    public function up(): void
    {
        $place = self::PLACE_REASONS;
        $release = self::RELEASE_REASONS;
        $reference = self::REFERENCE;

        DB::unprepared(<<<SQL
            CREATE TABLE retention_holds (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                scope varchar(16) NOT NULL CONSTRAINT retention_holds_scope_check CHECK (scope IN ('school', 'platform')),
                school_id uuid NULL REFERENCES schools (id) ON DELETE RESTRICT,
                reason_code varchar(32) NOT NULL CONSTRAINT retention_holds_reason_check CHECK (reason_code IN ({$place})),
                reference varchar(64) NULL CONSTRAINT retention_holds_reference_check CHECK (reference ~ {$reference}),
                placed_via varchar(32) NOT NULL CONSTRAINT retention_holds_via_check CHECK (placed_via IN ('operator_command', 'configuration_reconciliation', 'migration')),
                placed_by_login text NOT NULL,
                placed_at timestamp NOT NULL,
                released_at timestamp NULL,
                released_by_login text NULL,
                release_reason_code varchar(32) NULL CONSTRAINT retention_holds_release_reason_check CHECK (release_reason_code IN ({$release})),
                release_reference varchar(64) NULL CONSTRAINT retention_holds_release_reference_check CHECK (release_reference ~ {$reference}),
                CONSTRAINT retention_holds_scope_shape_check CHECK ((scope = 'school') = (school_id IS NOT NULL)),
                CONSTRAINT retention_holds_release_shape_check CHECK (
                    (released_at IS NULL) = (released_by_login IS NULL) AND (released_at IS NULL) = (release_reason_code IS NULL)
                    AND (released_at IS NOT NULL OR release_reference IS NULL) AND (released_at IS NULL OR released_at >= placed_at))
            );
            CREATE UNIQUE INDEX retention_holds_one_active_school ON retention_holds (school_id) WHERE scope = 'school' AND released_at IS NULL;
            CREATE UNIQUE INDEX retention_holds_one_active_platform ON retention_holds (scope) WHERE scope = 'platform' AND released_at IS NULL;
            REVOKE ALL ON retention_holds FROM PUBLIC;
            REVOKE ALL ON retention_holds FROM school_os_app;

            -- History is never rewritten or removed, by any role: an INSERT is a placement (attributed by the
            -- database), the one UPDATE is a release of an active hold, DELETE and TRUNCATE are refused.
            CREATE FUNCTION retention_holds_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.released_at IS NOT NULL OR NEW.released_by_login IS NOT NULL OR NEW.release_reason_code IS NOT NULL OR NEW.release_reference IS NOT NULL THEN
                        RAISE EXCEPTION 'retention_holds: a hold is placed active; release it explicitly';
                    END IF;
                    NEW.placed_by_login := session_user::text;
                    IF NEW.placed_via <> 'migration' OR NEW.placed_at IS NULL THEN
                        NEW.placed_at := now() AT TIME ZONE 'UTC';
                    END IF;
                    RETURN NEW;
                ELSIF TG_OP = 'UPDATE' THEN
                    IF OLD.released_at IS NOT NULL THEN
                        RAISE EXCEPTION 'retention_holds: a released hold is history and never changes';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.scope IS DISTINCT FROM OLD.scope OR NEW.school_id IS DISTINCT FROM OLD.school_id
                       OR NEW.reason_code IS DISTINCT FROM OLD.reason_code OR NEW.reference IS DISTINCT FROM OLD.reference
                       OR NEW.placed_via IS DISTINCT FROM OLD.placed_via OR NEW.placed_by_login IS DISTINCT FROM OLD.placed_by_login
                       OR NEW.placed_at IS DISTINCT FROM OLD.placed_at OR NEW.release_reason_code IS NULL THEN
                        RAISE EXCEPTION 'retention_holds: the only change is the release of an active hold';
                    END IF;
                    NEW.released_by_login := session_user::text;
                    NEW.released_at := now() AT TIME ZONE 'UTC';
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'retention_holds: hold history is never deleted';
            END;
            \$\$;
            CREATE TRIGGER trg_retention_holds_guard BEFORE INSERT OR UPDATE OR DELETE ON retention_holds
                FOR EACH ROW EXECUTE FUNCTION retention_holds_guard();
            CREATE TRIGGER trg_retention_holds_no_truncate BEFORE TRUNCATE ON retention_holds
                FOR EACH STATEMENT EXECUTE FUNCTION retention_holds_guard();
            REVOKE ALL ON FUNCTION retention_holds_guard() FROM PUBLIC;

            CREATE FUNCTION retention_hold_place(p_scope text, p_school_id uuid, p_reason_code text, p_reference text, p_via text)
                RETURNS TABLE (hold_id uuid, created boolean) LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_id uuid;
            BEGIN
                IF p_via NOT IN ('operator_command', 'configuration_reconciliation') THEN
                    RAISE EXCEPTION 'retention_hold_place: not a placement path (retention_hold_path)';
                END IF;
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('retention.holds', 0));
                SELECT id INTO v_id FROM public.retention_holds
                 WHERE released_at IS NULL AND scope = p_scope AND school_id IS NOT DISTINCT FROM p_school_id;
                IF FOUND THEN
                    RETURN QUERY SELECT v_id, false;
                    RETURN;
                END IF;
                INSERT INTO public.retention_holds (scope, school_id, reason_code, reference, placed_via, placed_by_login, placed_at)
                VALUES (p_scope, p_school_id, p_reason_code, p_reference, p_via, session_user::text, now() AT TIME ZONE 'UTC')
                RETURNING id INTO v_id;
                RETURN QUERY SELECT v_id, true;
            END;
            \$\$;

            CREATE FUNCTION retention_hold_release(p_scope text, p_school_id uuid, p_reason_code text, p_reference text)
                RETURNS uuid LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_id uuid;
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('retention.holds', 0));
                UPDATE public.retention_holds SET release_reason_code = p_reason_code, release_reference = p_reference,
                       released_at = now() AT TIME ZONE 'UTC', released_by_login = session_user::text
                 WHERE released_at IS NULL AND scope = p_scope AND school_id IS NOT DISTINCT FROM p_school_id
                 RETURNING id INTO v_id;
                IF v_id IS NULL THEN
                    RAISE EXCEPTION 'retention_hold_release: no active hold for that scope (retention_hold_not_active)';
                END IF;
                RETURN v_id;
            END;
            \$\$;

            -- Inside a destructive definer: refuse while the platform, or this School, is held.
            CREATE FUNCTION retention_assert_not_held(p_school_id uuid) RETURNS void
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(pg_catalog.hashtextextended('retention.holds', 0));
                IF EXISTS (SELECT 1 FROM public.retention_holds
                            WHERE released_at IS NULL
                              AND (scope = 'platform' OR (p_school_id IS NOT NULL AND scope = 'school' AND school_id = p_school_id))) THEN
                    RAISE EXCEPTION 'retention: a retention hold is active (retention_hold)';
                END IF;
            END;
            \$\$;

            CREATE FUNCTION retention_hold_active_scopes() RETURNS TABLE (scope text, school_id uuid)
                LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
                SELECT h.scope::text, h.school_id FROM public.retention_holds h WHERE h.released_at IS NULL ORDER BY h.scope, h.school_id
            \$\$;

            REVOKE ALL ON FUNCTION retention_hold_place(text, uuid, text, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION retention_hold_release(text, uuid, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION retention_assert_not_held(uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION retention_hold_active_scopes() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_hold_active_scopes() TO school_os_retention;

            -- Migrate the mirror: each row is an active School hold. Then prove it before dropping it.
            INSERT INTO retention_holds (scope, school_id, reason_code, placed_via, placed_by_login, placed_at)
            SELECT 'school', school_id, 'configuration_transition', 'migration', session_user::text, held_since FROM retention_school_holds;
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.retention_school_holds o WHERE NOT EXISTS (
                        SELECT 1 FROM public.retention_holds h WHERE h.scope = 'school' AND h.school_id = o.school_id AND h.released_at IS NULL)) THEN
                    RAISE EXCEPTION 'retention_holds: a migrated School hold is not active -- refusing to drop the mirror';
                END IF;
            END
            \$\$;
            DROP TABLE retention_school_holds;
            SQL);

        $this->prologue('PERFORM public.retention_assert_not_held(p_school_id);');
    }

    public function down(): void
    {
        if (DB::table('retention_holds')->where('scope', 'platform')->orWhereNotNull('released_at')->exists()) {
            throw new RuntimeException('Refusing to roll back: retention_holds holds a platform hold or release history, which the RH.2 mirror cannot represent. Rolling back would release a hold or destroy its history.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE retention_school_holds (
                school_id uuid PRIMARY KEY REFERENCES schools (id) ON DELETE RESTRICT,
                source varchar(16) NOT NULL DEFAULT 'config' CONSTRAINT retention_school_holds_source_check CHECK (source IN ('config')),
                held_since timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC')
            );
            REVOKE ALL ON retention_school_holds FROM PUBLIC;
            REVOKE ALL ON retention_school_holds FROM school_os_app;
            GRANT SELECT (school_id) ON retention_school_holds TO school_os_retention;
            INSERT INTO retention_school_holds (school_id, held_since)
            SELECT school_id, placed_at FROM retention_holds WHERE scope = 'school' AND released_at IS NULL;
            SQL);

        // The RH.2 hold check, byte for byte (the continuation lines carry the function body's own indentation).
        $this->prologue("IF EXISTS (SELECT 1 FROM public.retention_school_holds WHERE school_id = p_school_id) THEN\n        RAISE EXCEPTION 'retention: the School is under a retention hold (retention_hold)';\n    END IF;");

        DB::unprepared(<<<'SQL'
            DROP FUNCTION retention_hold_active_scopes();
            DROP FUNCTION retention_assert_not_held(uuid);
            DROP FUNCTION retention_hold_release(text, uuid, text, text);
            DROP FUNCTION retention_hold_place(text, uuid, text, text, text);
            DROP TABLE retention_holds;
            DROP FUNCTION retention_holds_guard();
            SQL);
    }

    /** The HRX prologue (2026_11_26_090000), with $hold as its hold check -- otherwise byte for byte. */
    private function prologue(string $hold): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION retention_lock_hrx_employee(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS uuid[]
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_records uuid[];
                v_record uuid;
            BEGIN
                -- The authorized retention identity, checked on the authenticated login (session_user).
                IF NOT (session_user = 'school_os_retention'::name) THEN
                    RAISE EXCEPTION 'retention: not the authorized retention identity (retention_privilege)';
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                -- The E21 legal hold, at the destructive boundary.
                {$hold}
                PERFORM public.retention_assert_payroll_employee_floor(p_school_id, p_employee_id, p_cutoff);
                SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_records
                  FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id;
                FOREACH v_record IN ARRAY v_records LOOP
                    PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('hrx.staff_employment:' || p_school_id::text || ':' || v_record::text, 0));
                END LOOP;
                RETURN v_records;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_lock_hrx_employee(uuid, uuid, date) FROM PUBLIC;
            SQL);
    }
};
