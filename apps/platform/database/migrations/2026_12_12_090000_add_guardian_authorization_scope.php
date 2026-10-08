<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POR.1 (ADR 0070 §8.2; amends ADR 0045 and CLAUDE.md rule 25): a fourth
 * authorization scope, `guardian`, so Guardian portal authority is separated
 * from staff authority by the database, not by convention.
 *
 * - `roles.scope` admits `guardian`. A `portal.*` capability lives in the
 *   `guardian` namespace and nothing else does; the existing role_capabilities
 *   trigger (role scope = capability namespace) then refuses a `portal.*` key
 *   on any staff, platform or Group role and any staff key on a Guardian role.
 * - `membership_role_assignments` accepts `school` and `guardian` roles. A
 *   NEW `guardian` grant needs an active Guardian account link on the same
 *   membership in the same School (checked on INSERT only: revoking a grant
 *   never re-checks the link).
 * - An active Guardian account link cannot be ended while its membership
 *   still holds an active `guardian` grant: the grant is revoked first. The
 *   runtime role has no DELETE on links; deletion is cascade or retention of
 *   already-revoked links only.
 * - Two revocation reasons: `staff_offboarded` (staff authority removed from a
 *   membership that keeps its Guardian identity, ADR 0059 amendment) and
 *   `guardian_link_revoked` (portal authority removed with the link).
 *
 * down() refuses while any `guardian` grant or new-reason row exists (history
 * cannot be dropped silently); otherwise it removes the reseedable Guardian
 * catalog rows and restores the three-scope rules exactly.
 */
return new class extends Migration
{
    private const OLD_REASONS = "'revoked', 'membership_suspended', 'reactivation_reset'";

    private const REASONS = "'revoked', 'membership_suspended', 'reactivation_reset', 'staff_offboarded', 'guardian_link_revoked'";

    public function up(): void
    {
        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_scope_check');
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('platform', 'school', 'group', 'guardian'))");

        DB::statement("ALTER TABLE capabilities ADD CONSTRAINT capabilities_guardian_namespace_check CHECK ((key LIKE 'portal.%') = (namespace = 'guardian'))");

        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_revocation_check');
        DB::statement($this->revocationCheck(self::REASONS));

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_membership_role_assignment_scope()
            RETURNS trigger AS $$
            DECLARE
                role_scope text;
            BEGIN
                SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                IF role_scope IS DISTINCT FROM 'school' AND role_scope IS DISTINCT FROM 'guardian' THEN
                    RAISE EXCEPTION
                        'membership_role_assignments.role_id must reference a role with scope=school or scope=guardian (got %)',
                        role_scope;
                END IF;
                IF role_scope = 'guardian' AND TG_OP = 'INSERT' AND NOT EXISTS (
                    SELECT 1 FROM student_guardian_account_links l
                    WHERE l.school_membership_id = NEW.school_membership_id
                      AND l.school_id = NEW.school_id
                      AND l.status = 'active'
                      AND l.guardian_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION
                        'membership_role_assignments: a guardian-scope grant needs an active Guardian account link on the same membership';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION assert_guardian_link_has_no_active_portal_grant()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.guardian_id IS NOT NULL AND OLD.status = 'active'
                    AND (NEW.status IS DISTINCT FROM 'active'
                        OR NEW.school_membership_id IS DISTINCT FROM OLD.school_membership_id
                        OR NEW.guardian_id IS DISTINCT FROM OLD.guardian_id)
                    AND EXISTS (
                        SELECT 1 FROM membership_role_assignments mra
                        JOIN roles r ON r.id = mra.role_id
                        WHERE mra.school_membership_id = OLD.school_membership_id
                          AND mra.school_id = OLD.school_id
                          AND mra.revoked_at IS NULL
                          AND r.scope = 'guardian'
                    ) THEN
                    RAISE EXCEPTION
                        'student_guardian_account_links: revoke the guardian-scope role grant before ending its Guardian account link';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_sgal_portal_grant_guard
                BEFORE UPDATE ON student_guardian_account_links
                FOR EACH ROW EXECUTE FUNCTION assert_guardian_link_has_no_active_portal_grant();
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // FORCE RLS hides every School's rows from the owner: lift it for these checks only.
            DB::statement('ALTER TABLE membership_role_assignments NO FORCE ROW LEVEL SECURITY');
            $guardianGrants = DB::selectOne("SELECT count(*) AS c FROM membership_role_assignments mra JOIN roles r ON r.id = mra.role_id WHERE r.scope = 'guardian'")->c;
            $newReasons = DB::selectOne("SELECT count(*) AS c FROM membership_role_assignments WHERE revocation_reason IN ('staff_offboarded', 'guardian_link_revoked')")->c;
            DB::statement('ALTER TABLE membership_role_assignments FORCE ROW LEVEL SECURITY');

            if ((int) $guardianGrants > 0 || (int) $newReasons > 0) {
                throw new RuntimeException('Refusing to roll back the guardian scope: Guardian portal grants or staff/Guardian lifecycle history exist (ADR 0070). Removing them would destroy authorization history.');
            }

            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_sgal_portal_grant_guard ON student_guardian_account_links;
                DROP FUNCTION IF EXISTS assert_guardian_link_has_no_active_portal_grant();

                CREATE OR REPLACE FUNCTION assert_membership_role_assignment_scope()
                RETURNS trigger AS $$
                DECLARE
                    role_scope text;
                BEGIN
                    SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                    IF role_scope IS DISTINCT FROM 'school' THEN
                        RAISE EXCEPTION
                            'membership_role_assignments.role_id must reference a role with scope=school (got %)',
                            role_scope;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            // The Guardian catalog rows are reference data the seeder recreates.
            DB::statement("DELETE FROM role_capabilities WHERE role_id IN (SELECT id FROM roles WHERE scope = 'guardian')");
            DB::statement("DELETE FROM roles WHERE scope = 'guardian'");
            DB::statement("DELETE FROM role_capabilities WHERE capability_key IN (SELECT key FROM capabilities WHERE namespace = 'guardian')");
            DB::statement("DELETE FROM capabilities WHERE namespace = 'guardian'");

            DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_revocation_check');
            DB::statement($this->revocationCheck(self::OLD_REASONS));

            DB::statement('ALTER TABLE capabilities DROP CONSTRAINT IF EXISTS capabilities_guardian_namespace_check');
            DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_scope_check');
            DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('platform', 'school', 'group'))");
        });
    }

    private function revocationCheck(string $reasons): string
    {
        return 'ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_revocation_check '
            .'CHECK ((revoked_at IS NULL) = (revocation_reason IS NULL) AND (revoked_at IS NOT NULL OR revoked_by_user_id IS NULL) '
            .'AND (revocation_reason IS NULL OR revocation_reason IN ('.$reasons.')))';
    }
};
