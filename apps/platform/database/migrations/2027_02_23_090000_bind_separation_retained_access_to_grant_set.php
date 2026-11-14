<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C35 WO-20 (HRC-4, owner decision D-C35-2; D-WO20-1, D-WO20-2).
 *
 * "Access kept" on a finalized separation was one timestamp: whatever School grants the account held later
 * still read RETAINED, so a role granted after the recording never blocked completion, and a revoke-then-grant
 * kept the count but not the set. The recording now binds the SET of the account's live `school`-scope role
 * grants (`membership_role_assignments.id`, sorted) at that moment, in `access_retained_grant_ids`:
 *
 * - **At recording** the stored set must equal the account's live grants exactly (active membership in this
 *   School, unrevoked, `school` scope), read here from Identity's own rows: the database refuses a forged,
 *   partial, padded or other-School set, and refuses recording kept access with none.
 * - **Immutable** once written (D-WO20-1: off-board only; a kept record is never re-recorded or rewritten).
 * - **Never back-filled:** a legacy record (recorded before this migration, no set) can never gain one later, so
 *   today's roles are never written as history (D-WO20-2: such a record reads OPEN, never RETAINED).
 * - **Born empty:** a case is inserted without a set.
 * - **The recording's time is evidence too** (review L1): recorded at and by are set together
 *   (`employment_separation_cases_retained_pair`), and the time, once set, never changes. A legacy record is
 *   one with a recorder and no set.
 *
 * SECURITY INVOKER under the caller's RLS, `search_path` pinned with `pg_temp` last, every reference
 * schema-qualified. Who recorded it stays an application boundary (rule 102). No existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employment_separation_cases ADD COLUMN access_retained_grant_ids uuid[]');
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_retained_grants_shape
            CHECK (access_retained_grant_ids IS NULL OR (access_retained_by_user_id IS NOT NULL AND cardinality(access_retained_grant_ids) >= 1))');
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_retained_pair
            CHECK ((access_retained_at IS NULL) = (access_retained_by_user_id IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.hr_separation_retained_grants_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_live uuid[];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.access_retained_grant_ids IS NOT NULL THEN
                        RAISE EXCEPTION 'a separation case is born without kept access (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.access_retained_grant_ids IS NOT NULL AND NEW.access_retained_grant_ids IS DISTINCT FROM OLD.access_retained_grant_ids THEN
                    RAISE EXCEPTION 'the kept School role set is immutable (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.access_retained_at IS NOT NULL AND NEW.access_retained_at IS DISTINCT FROM OLD.access_retained_at THEN
                    RAISE EXCEPTION 'the kept access record is immutable (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.access_retained_by_user_id IS NULL AND NEW.access_retained_by_user_id IS NOT NULL THEN
                    SELECT array_agg(a.id ORDER BY a.id) INTO v_live
                      FROM public.school_memberships m
                      JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL
                      JOIN public.roles r ON r.id = a.role_id AND r.scope = 'school'
                     WHERE m.school_id = NEW.school_id AND m.status = 'active'
                       AND m.user_id = public.hr_separation_subject_user(NEW.employment_record_id);
                    IF v_live IS NULL OR NEW.access_retained_grant_ids IS DISTINCT FROM v_live THEN
                        RAISE EXCEPTION 'kept access binds exactly the account''s live School role grants (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.access_retained_grant_ids IS DISTINCT FROM OLD.access_retained_grant_ids THEN
                    RAISE EXCEPTION 'the kept School role set is written only with the recording (hr_separation_retained_grants_guard)' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employment_separation_cases_retained_grants BEFORE INSERT OR UPDATE ON employment_separation_cases
                FOR EACH ROW EXECUTE FUNCTION public.hr_separation_retained_grants_guard();
            SQL);
    }

    /** Drops the bound sets with the column; rollback runs only in local and testing (rule 143), production corrects forward. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employment_separation_cases_retained_grants ON employment_separation_cases;
            DROP FUNCTION IF EXISTS public.hr_separation_retained_grants_guard();
            ALTER TABLE employment_separation_cases DROP CONSTRAINT IF EXISTS employment_separation_cases_retained_pair;
            ALTER TABLE employment_separation_cases DROP CONSTRAINT IF EXISTS employment_separation_cases_retained_grants_shape;
            ALTER TABLE employment_separation_cases DROP COLUMN IF EXISTS access_retained_grant_ids;
            SQL);
    }
};
