<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SR.1 (ADR 0071 §11): the staff role catalogue's database foundation.
 *
 * 1. The runtime role (`school_os_app`) can no longer write the catalogue:
 *    INSERT/UPDATE/DELETE/TRUNCATE on `roles`, `role_capabilities` and
 *    `capabilities` are revoked. Catalogue rows are written only by the
 *    migration/admin role (migrations, `CapabilityAndRoleSeeder`).
 * 2. Grant history survives: `membership_role_assignments.role_id` becomes
 *    ON DELETE RESTRICT (it was CASCADE, so deleting a role erased every
 *    grant of it -- referential actions run with the table owner's rights
 *    and the history guard fires only on INSERT/UPDATE). Roles are retired,
 *    never deleted.
 * 3. Role identity is immutable: `key`, `scope` and `is_system` never change
 *    after insert (an `is_system` flip would move a row into or out of the
 *    staff catalogue), and retirement is one-way (`trg_roles_identity`).
 * 4. `roles.retired_at`: a retired role keeps its history, receives no new
 *    grant and leaves the catalogue. Grants already active stay active until
 *    revoked (retirement stops NEW grants only, ADR 0071 §11.4).
 * 5. `capabilities.grant_right`: the SR.2 class grant-right coverage map
 *    (nullable self-reference). SR.1 adds the column and the coverage logic
 *    only; no grant-right capability exists until SR.2 seeds one.
 * 6. `trg_membership_role_assignments_grantor` (BEFORE INSERT): every new
 *    grant needs a non-retired role with at least one capability. A
 *    `school`-scope grant written below the administrative boundary must
 *    name its assigning User, who must be enabled, not the grantee, hold an
 *    ACTIVE membership in the SAME School with `school.roles.manage`, and
 *    cover every capability of the role -- held through active `school`-scope
 *    grants, or covered by a held grant right. The issuer's membership and
 *    grants are read FOR SHARE, so a concurrent suspension or revocation
 *    either commits first (and this grant is refused) or waits for it.
 *    Exceptions, both narrow:
 *    - the trusted administrative role (`pg_has_role(current_user, owner)`,
 *      the Phase 0O.1A pattern; the runtime role is never a member);
 *    - the ADR 0047 bootstrap: role `school_admin`, on a School still
 *      `provisioning`, assigned by an enabled User holding
 *      `platform.schools.manage` through an active platform grant.
 *    Guardian-scope grants keep their own link rule
 *    (`trg_membership_role_assignments_scope`); other scopes are refused there.
 *
 * Rollback: down() refuses while any role is retired or any grant right is
 * mapped (that would discard lifecycle data), then restores the previous
 * state exactly -- including the runtime privileges and the CASCADE -- so a
 * rollback is a true inverse, as the earlier history-guard migration's is.
 */
return new class extends Migration
{
    private const RUNTIME_ROLE = 'school_os_app';

    public function up(): void
    {
        DB::statement('ALTER TABLE roles ADD COLUMN retired_at timestamp(0) without time zone NULL');

        DB::statement('ALTER TABLE capabilities ADD COLUMN grant_right varchar(255) NULL');
        DB::statement('ALTER TABLE capabilities ADD CONSTRAINT capabilities_grant_right_foreign FOREIGN KEY (grant_right) REFERENCES capabilities (key) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE capabilities ADD CONSTRAINT capabilities_grant_right_not_self CHECK (grant_right IS NULL OR grant_right <> key)');

        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_role_id_foreign');
        DB::statement('ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_role_id_foreign FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_role_identity_immutable() RETURNS trigger AS $$
            BEGIN
                IF NEW.key IS DISTINCT FROM OLD.key OR NEW.scope IS DISTINCT FROM OLD.scope OR NEW.is_system IS DISTINCT FROM OLD.is_system THEN
                    RAISE EXCEPTION 'roles: key, scope and is_system are permanent; retire the role instead (ADR 0071)';
                END IF;
                IF OLD.retired_at IS NOT NULL AND NEW.retired_at IS DISTINCT FROM OLD.retired_at THEN
                    RAISE EXCEPTION 'roles: retirement is permanent (ADR 0071)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_roles_identity
                BEFORE UPDATE ON roles
                FOR EACH ROW EXECUTE FUNCTION assert_role_identity_immutable();

            CREATE OR REPLACE FUNCTION assert_capability_grant_right() RETURNS trigger AS $$
            BEGIN
                IF NEW.grant_right IS NOT NULL AND EXISTS (SELECT 1 FROM capabilities g WHERE g.key = NEW.grant_right AND g.grant_right IS NOT NULL) THEN
                    RAISE EXCEPTION 'capabilities: a grant right is never itself covered by another grant right';
                END IF;
                IF EXISTS (SELECT 1 FROM capabilities c WHERE c.grant_right = NEW.key) AND NEW.grant_right IS NOT NULL THEN
                    RAISE EXCEPTION 'capabilities: a grant right is never itself covered by another grant right';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_capabilities_grant_right
                BEFORE INSERT OR UPDATE OF grant_right ON capabilities
                FOR EACH ROW EXECUTE FUNCTION assert_capability_grant_right();

            CREATE OR REPLACE FUNCTION assert_membership_role_assignment_grantor() RETURNS trigger AS $$
            DECLARE
                v_role roles%ROWTYPE;
                v_target_user uuid;
                v_issuer_membership uuid;
                v_held text[];
                v_uncovered integer;
            BEGIN
                SELECT * INTO v_role FROM roles WHERE id = NEW.role_id;
                IF NOT FOUND THEN
                    RETURN NEW; -- the foreign key reports it
                END IF;

                IF v_role.retired_at IS NOT NULL THEN
                    RAISE EXCEPTION 'membership_role_assignments: role % is retired and cannot be granted', v_role.key;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM role_capabilities WHERE role_id = v_role.id) THEN
                    RAISE EXCEPTION 'membership_role_assignments: role % has no capabilities and cannot be granted', v_role.key;
                END IF;

                -- Guardian-scope grants keep their own link rule; other scopes are
                -- refused by trg_membership_role_assignments_scope.
                IF v_role.scope IS DISTINCT FROM 'school' THEN
                    RETURN NEW;
                END IF;

                -- The trusted administrative boundary (Phase 0O.1A pattern).
                IF pg_has_role(current_user, (SELECT relowner FROM pg_class WHERE oid = TG_RELID), 'USAGE') THEN
                    RETURN NEW;
                END IF;

                IF NEW.assigned_by_user_id IS NULL THEN
                    RAISE EXCEPTION 'membership_role_assignments: a runtime School-role grant must name its assigning user';
                END IF;

                -- ADR 0047 bootstrap: the first School Administrator of a School
                -- still being provisioned, by a platform School manager.
                IF v_role.key = 'school_admin'
                    AND EXISTS (SELECT 1 FROM schools s WHERE s.id = NEW.school_id AND s.status = 'provisioning')
                    AND EXISTS (
                        SELECT 1 FROM platform_role_assignments pra
                        JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
                        JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key = 'platform.schools.manage'
                        WHERE pra.user_id = NEW.assigned_by_user_id AND pra.revoked_at IS NULL
                    ) THEN
                    RETURN NEW;
                END IF;

                SELECT user_id INTO v_target_user FROM school_memberships WHERE id = NEW.school_membership_id;
                IF v_target_user = NEW.assigned_by_user_id THEN
                    RAISE EXCEPTION 'membership_role_assignments: the assigning user cannot grant a role to themselves';
                END IF;

                IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.assigned_by_user_id AND NOT is_disabled) THEN
                    RAISE EXCEPTION 'membership_role_assignments: the assigning user is unknown or disabled';
                END IF;

                SELECT m.id INTO v_issuer_membership FROM school_memberships m
                WHERE m.user_id = NEW.assigned_by_user_id AND m.school_id = NEW.school_id AND m.status = 'active'
                FOR SHARE;
                IF v_issuer_membership IS NULL THEN
                    RAISE EXCEPTION 'membership_role_assignments: the assigning user has no active membership in this School';
                END IF;

                PERFORM 1 FROM membership_role_assignments a
                WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL
                FOR SHARE;

                SELECT coalesce(array_agg(DISTINCT rc.capability_key), ARRAY[]::text[]) INTO v_held
                FROM membership_role_assignments a
                JOIN roles r ON r.id = a.role_id AND r.scope = 'school'
                JOIN role_capabilities rc ON rc.role_id = a.role_id
                WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL;

                IF NOT ('school.roles.manage' = ANY (v_held)) THEN
                    RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold school.roles.manage in this School';
                END IF;

                SELECT count(*) INTO v_uncovered
                FROM role_capabilities t
                JOIN capabilities c ON c.key = t.capability_key
                WHERE t.role_id = v_role.id
                  AND NOT (t.capability_key = ANY (v_held))
                  AND (c.grant_right IS NULL OR NOT (c.grant_right = ANY (v_held)));
                IF v_uncovered > 0 THEN
                    RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold or cover every capability of role %', v_role.key;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_membership_role_assignments_grantor
                BEFORE INSERT ON membership_role_assignments
                FOR EACH ROW EXECUTE FUNCTION assert_membership_role_assignment_grantor();
            SQL);

        foreach (['roles', 'role_capabilities', 'capabilities'] as $table) {
            DB::statement("REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON {$table} FROM ".self::RUNTIME_ROLE);
        }
    }

    public function down(): void
    {
        $retired = DB::table('roles')->whereNotNull('retired_at')->exists();
        $mapped = DB::table('capabilities')->whereNotNull('grant_right')->exists();
        if ($retired || $mapped) {
            throw new RuntimeException('Refusing to roll back SR.1: retired roles or grant-right mappings exist and would be lost.');
        }

        foreach (['roles', 'role_capabilities', 'capabilities'] as $table) {
            DB::statement("GRANT INSERT, UPDATE, DELETE ON {$table} TO ".self::RUNTIME_ROLE);
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_membership_role_assignments_grantor ON membership_role_assignments;
            DROP FUNCTION IF EXISTS assert_membership_role_assignment_grantor();
            DROP TRIGGER IF EXISTS trg_capabilities_grant_right ON capabilities;
            DROP FUNCTION IF EXISTS assert_capability_grant_right();
            DROP TRIGGER IF EXISTS trg_roles_identity ON roles;
            DROP FUNCTION IF EXISTS assert_role_identity_immutable();
            SQL);

        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_role_id_foreign');
        DB::statement('ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_role_id_foreign FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE capabilities DROP CONSTRAINT IF EXISTS capabilities_grant_right_not_self');
        DB::statement('ALTER TABLE capabilities DROP CONSTRAINT IF EXISTS capabilities_grant_right_foreign');
        DB::statement('ALTER TABLE capabilities DROP COLUMN grant_right');
        DB::statement('ALTER TABLE roles DROP COLUMN retired_at');
    }
};
