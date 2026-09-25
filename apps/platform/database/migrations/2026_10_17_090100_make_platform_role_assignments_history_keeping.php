<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.7 (ADR 0046 section 4): platform role grants keep their
 * history, and the database itself refuses escalation.
 *
 * - A revocation sets `revoked_at` / `revoked_by_user_id` once; the row
 *   stays and can never become active again; re-granting inserts a new
 *   row. Uniqueness is on ACTIVE rows only. The runtime role cannot
 *   DELETE a grant, and deleting a user or role no longer erases its
 *   grants (RESTRICT, instead of CASCADE / SET NULL).
 * - `granted_by_user_id` NULL means provisioned out of band (seeding,
 *   later the operator console); set means a runtime grant. Nobody grants
 *   or revokes their own assignment (CHECKs).
 * - A runtime grant, and any revocation, must name a `runtime_assignable`
 *   role: the root role `platform_super_admin` can therefore never be
 *   granted or revoked through the application (trigger).
 * The existing `trg_platform_role_assignments_scope` (platform roles
 * only, CLAUDE.md rule 25) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_role_assignments', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['granted_by_user_id']);

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUuid('revoked_by_user_id')->nullable()->after('granted_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('revoked_by_user_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform_role_assignments
                ADD CONSTRAINT platform_role_assignments_no_self_grant
                    CHECK (granted_by_user_id IS NULL OR granted_by_user_id <> user_id),
                ADD CONSTRAINT platform_role_assignments_no_self_revoke
                    CHECK (revoked_by_user_id IS NULL OR revoked_by_user_id <> user_id),
                ADD CONSTRAINT platform_role_assignments_revocation_check
                    CHECK ((revoked_at IS NULL) = (revoked_by_user_id IS NULL))
            SQL);

        DB::statement('CREATE UNIQUE INDEX platform_role_assignments_one_active ON platform_role_assignments (user_id, role_id) WHERE revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_platform_role_assignment_governance() RETURNS trigger AS $$
            DECLARE
                assignable boolean;
            BEGIN
                SELECT runtime_assignable INTO assignable FROM roles WHERE id = NEW.role_id;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'platform_role_assignments: a grant cannot be created already revoked';
                    END IF;
                    IF NEW.granted_by_user_id IS NOT NULL AND NOT assignable THEN
                        RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be granted at runtime';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'platform_role_assignments: a revoked grant is immutable and cannot be reactivated';
                END IF;
                IF NEW.revoked_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.role_id IS DISTINCT FROM OLD.role_id
                    OR NEW.granted_by_user_id IS DISTINCT FROM OLD.granted_by_user_id
                    OR NEW.granted_at IS DISTINCT FROM OLD.granted_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'platform_role_assignments: the only permitted change is revocation';
                END IF;
                IF NOT assignable THEN
                    RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be revoked at runtime';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_platform_role_assignments_governance
                BEFORE INSERT OR UPDATE ON platform_role_assignments
                FOR EACH ROW EXECUTE FUNCTION assert_platform_role_assignment_governance();
            SQL);

        TenantRls::revokeDelete('platform_role_assignments');
    }

    public function down(): void
    {
        DB::statement('GRANT DELETE ON platform_role_assignments TO school_os_app');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_platform_role_assignments_governance ON platform_role_assignments;
            DROP FUNCTION IF EXISTS assert_platform_role_assignment_governance();
            SQL);

        DB::statement('DROP INDEX IF EXISTS platform_role_assignments_one_active');
        DB::statement(<<<'SQL'
            ALTER TABLE platform_role_assignments
                DROP CONSTRAINT IF EXISTS platform_role_assignments_no_self_grant,
                DROP CONSTRAINT IF EXISTS platform_role_assignments_no_self_revoke,
                DROP CONSTRAINT IF EXISTS platform_role_assignments_revocation_check
            SQL);

        // History rows cannot fit the old total UNIQUE (user_id, role_id):
        // a rollback keeps only the active assignments, the pre-0N.7 shape.
        DB::table('platform_role_assignments')->whereNotNull('revoked_at')->delete();

        Schema::table('platform_role_assignments', function (Blueprint $table) {
            $table->dropForeign(['revoked_by_user_id']);
            $table->dropColumn(['revoked_by_user_id', 'revoked_at']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['granted_by_user_id']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['user_id', 'role_id']);
        });
    }
};
