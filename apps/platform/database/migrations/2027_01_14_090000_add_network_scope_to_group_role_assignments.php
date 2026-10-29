<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.12E (ADR 0080 §10, OD-12.13/OD-12.14): Network roles and Region/Zone
 * scope on the EXISTING Group grant (`group_role_assignments`, ADR 0045 §3)
 * -- no parallel assignment table.
 *
 * - `region_id` / `zone_id`: an optional Network-governance READ scope.
 *   Both NULL = the whole Group (every existing grant, unchanged). At most
 *   one is set (a Zone scope names only its Zone). Composite keys keep each
 *   inside the grant's own Group. Never a School scope: a Region or Zone
 *   activates no School capability (nothing School-side reads these columns).
 * - Database-enforced (`trg_group_role_assignments_network`):
 *   - a scoped grant is allowed only for a role whose EVERY capability is
 *     `group.network.view` (decided by capability, never by role name), so
 *     management or elevation can never be Region/Zone scoped;
 *   - a role carrying Network management (`group.network.manage`,
 *     `group.network.operators.manage`) is granted only by a holder of
 *     `platform.school_group_grants.manage` (a Network Manager never
 *     appoints a Network Manager);
 *   - a read-only Network role (`group.network.view` only) is granted by
 *     that platform holder or by a holder of a whole-Group
 *     `group.network.operators.manage` grant in the SAME Group;
 *   - the scope never changes after creation (re-scoping = revoke + grant).
 *   The trusted administrative boundary (the table owner, Phase 0O.1A
 *   pattern) is exempt, as for every other grant trigger. Pre-existing
 *   `group_admin` grants and their grantor semantics are unchanged.
 *
 * No row is read or moved; these are platform tables without RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE group_role_assignments ADD COLUMN region_id uuid NULL, ADD COLUMN zone_id uuid NULL');
        DB::statement('CREATE INDEX group_role_assignments_region_id_index ON group_role_assignments (region_id) WHERE region_id IS NOT NULL');
        DB::statement('CREATE INDEX group_role_assignments_zone_id_index ON group_role_assignments (zone_id) WHERE zone_id IS NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE group_role_assignments
                ADD CONSTRAINT group_role_assignments_region_fk FOREIGN KEY (region_id, school_group_id)
                    REFERENCES school_group_regions (id, school_group_id) ON DELETE RESTRICT,
                ADD CONSTRAINT group_role_assignments_zone_fk FOREIGN KEY (zone_id, school_group_id)
                    REFERENCES school_group_zones (id, school_group_id) ON DELETE RESTRICT,
                ADD CONSTRAINT group_role_assignments_one_scope_check CHECK (num_nonnulls(region_id, zone_id) <= 1)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_group_role_assignment_network() RETURNS trigger AS $$
            DECLARE
                v_caps text[];
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.region_id IS DISTINCT FROM OLD.region_id OR NEW.zone_id IS DISTINCT FROM OLD.zone_id THEN
                        RAISE EXCEPTION 'group_role_assignments: a grant''s Region/Zone scope never changes (revoke and grant again)';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT coalesce(array_agg(DISTINCT capability_key), ARRAY[]::text[]) INTO v_caps
                FROM role_capabilities WHERE role_id = NEW.role_id;

                IF (NEW.region_id IS NOT NULL OR NEW.zone_id IS NOT NULL)
                    AND (cardinality(v_caps) = 0 OR v_caps <> ARRAY['group.network.view']::text[]) THEN
                    RAISE EXCEPTION 'group_role_assignments: only a read-only Network role can be Region or Zone scoped';
                END IF;

                -- The trusted administrative boundary (Phase 0O.1A pattern).
                IF pg_has_role(current_user, (SELECT relowner FROM pg_class WHERE oid = TG_RELID), 'USAGE') THEN
                    RETURN NEW;
                END IF;

                IF v_caps && ARRAY['group.network.manage', 'group.network.operators.manage']::text[] THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM platform_role_assignments pra
                        JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
                        JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key = 'platform.school_group_grants.manage'
                        WHERE pra.user_id = NEW.granted_by_user_id AND pra.revoked_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'group_role_assignments: Network management is granted only by the platform';
                    END IF;
                ELSIF v_caps = ARRAY['group.network.view']::text[] THEN
                    PERFORM 1 FROM group_role_assignments g
                    WHERE g.user_id = NEW.granted_by_user_id AND g.school_group_id = NEW.school_group_id AND g.revoked_at IS NULL
                    FOR SHARE;
                    IF NOT EXISTS (
                        SELECT 1 FROM platform_role_assignments pra
                        JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
                        JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key = 'platform.school_group_grants.manage'
                        WHERE pra.user_id = NEW.granted_by_user_id AND pra.revoked_at IS NULL
                    ) AND NOT EXISTS (
                        SELECT 1 FROM group_role_assignments g
                        JOIN users gu ON gu.id = g.user_id AND NOT gu.is_disabled
                        JOIN role_capabilities grc ON grc.role_id = g.role_id AND grc.capability_key = 'group.network.operators.manage'
                        WHERE g.user_id = NEW.granted_by_user_id AND g.school_group_id = NEW.school_group_id
                            AND g.revoked_at IS NULL AND g.region_id IS NULL AND g.zone_id IS NULL
                    ) THEN
                        RAISE EXCEPTION 'group_role_assignments: a Network operator is granted only by the platform or a Network Manager of the same Group';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_group_role_assignments_network
                BEFORE INSERT OR UPDATE ON group_role_assignments
                FOR EACH ROW EXECUTE FUNCTION assert_group_role_assignment_network();
            SQL);
    }

    public function down(): void
    {
        // Fail closed: dropping the scope would silently widen every active
        // Region/Zone-scoped viewer to the whole Group. Revoke them first.
        if (Schema::hasColumn('group_role_assignments', 'region_id')
            && DB::table('group_role_assignments')->whereNull('revoked_at')->where(fn ($q) => $q->whereNotNull('region_id')->orWhereNotNull('zone_id'))->exists()) {
            throw new RuntimeException('Revoke the active Region/Zone-scoped Network grants before rolling this migration back.');
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_group_role_assignments_network ON group_role_assignments;
            DROP FUNCTION IF EXISTS assert_group_role_assignment_network();
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE group_role_assignments
                DROP CONSTRAINT IF EXISTS group_role_assignments_one_scope_check,
                DROP CONSTRAINT IF EXISTS group_role_assignments_zone_fk,
                DROP CONSTRAINT IF EXISTS group_role_assignments_region_fk
            SQL);
        DB::statement('DROP INDEX IF EXISTS group_role_assignments_zone_id_index');
        DB::statement('DROP INDEX IF EXISTS group_role_assignments_region_id_index');
        if (Schema::hasColumn('group_role_assignments', 'region_id')) {
            DB::statement('ALTER TABLE group_role_assignments DROP COLUMN zone_id, DROP COLUMN region_id');
        }
    }
};
