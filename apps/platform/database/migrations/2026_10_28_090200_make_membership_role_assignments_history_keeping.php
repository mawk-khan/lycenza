<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0O.12B (ADR 0059 owner amendment): School role grants keep their
 * history, the pattern `platform_role_assignments` (ADR 0046) and
 * `group_role_assignments` (ADR 0045) already use -- runtime role
 * revocation is now a School action (off-boarding, role management).
 *
 * - A revocation sets `revoked_at`, `revoked_by_user_id` and a closed
 *   `revocation_reason` once; the row stays and can never become active
 *   again. Re-granting inserts a NEW row, so grant -> revoke -> grant are
 *   three distinct facts.
 * - Uniqueness holds for ACTIVE rows only (one active grant per membership
 *   and role).
 * - The runtime role can no longer DELETE a grant (history). A parent
 *   School's or membership's cascade is unaffected (TenantRls::revokeDelete).
 * - Every existing row becomes an active grant; nothing historical is
 *   rewritten.
 * - Forced RLS and `trg_membership_role_assignments_scope` are unchanged.
 *
 * down(): revoked history cannot fit the old total UNIQUE
 * (school_membership_id, role_id) -- a rollback keeps only the ACTIVE
 * grants, exactly like the platform role-history rollback (ADR 0046).
 */
return new class extends Migration
{
    private const REASONS = "'revoked', 'membership_suspended', 'reactivation_reset'";

    public function up(): void
    {
        Schema::table('membership_role_assignments', function (Blueprint $table) {
            $table->dropUnique(['school_membership_id', 'role_id']);
            $table->foreignUuid('revoked_by_user_id')->nullable()->after('assigned_at')->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('revoked_by_user_id');
            $table->string('revocation_reason', 32)->nullable()->after('revoked_at');
        });

        DB::statement('ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_revocation_check '
            .'CHECK ((revoked_at IS NULL) = (revocation_reason IS NULL) AND (revoked_at IS NOT NULL OR revoked_by_user_id IS NULL) '
            .'AND (revocation_reason IS NULL OR revocation_reason IN ('.self::REASONS.')))');
        DB::statement('CREATE UNIQUE INDEX membership_role_assignments_one_active ON membership_role_assignments (school_membership_id, role_id) WHERE revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION membership_role_assignments_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'membership_role_assignments: a grant cannot be created already revoked';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'membership_role_assignments: a revoked grant is immutable and cannot be reactivated';
                END IF;
                IF NEW.revoked_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.school_membership_id IS DISTINCT FROM OLD.school_membership_id
                    OR NEW.role_id IS DISTINCT FROM OLD.role_id
                    OR NEW.assigned_by_user_id IS DISTINCT FROM OLD.assigned_by_user_id
                    OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'membership_role_assignments: the only permitted change is revocation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_membership_role_assignments_history
                BEFORE INSERT OR UPDATE ON membership_role_assignments
                FOR EACH ROW EXECUTE FUNCTION membership_role_assignments_history_guard();
            SQL);

        TenantRls::revokeDelete('membership_role_assignments');
    }

    public function down(): void
    {
        DB::statement('GRANT DELETE ON membership_role_assignments TO school_os_app');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_membership_role_assignments_history ON membership_role_assignments;
            DROP FUNCTION IF EXISTS membership_role_assignments_history_guard();
            SQL);

        DB::statement('DROP INDEX IF EXISTS membership_role_assignments_one_active');
        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT IF EXISTS membership_role_assignments_revocation_check');

        // Revoked history cannot fit the old total UNIQUE: keep only the
        // active grants. The table is FORCE-RLS, so the owner lifts FORCE for
        // this one statement to see every School's rows.
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE membership_role_assignments NO FORCE ROW LEVEL SECURITY');
            DB::statement('DELETE FROM membership_role_assignments WHERE revoked_at IS NOT NULL');
            DB::statement('ALTER TABLE membership_role_assignments FORCE ROW LEVEL SECURITY');
        });

        Schema::table('membership_role_assignments', function (Blueprint $table) {
            $table->dropForeign(['revoked_by_user_id']);
            $table->dropColumn(['revoked_by_user_id', 'revoked_at', 'revocation_reason']);
            $table->unique(['school_membership_id', 'role_id']);
        });
    }
};
