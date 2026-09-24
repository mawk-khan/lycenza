<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.5 (ADR 0045 section 3): the human Group grant -- one User, one
 * School Group, one `group`-scope role. Platform-owned, no RLS (read
 * before any School context exists; no School route reads it). Sensitive
 * (DATA-CLASSIFICATION.md). Never a School membership, never copied to a
 * School.
 *
 * Database-enforced:
 * - the role is `group`-scoped (trigger, the pattern of CLAUDE.md rule 25);
 * - nobody grants themselves Group authority (CHECK);
 * - at most one ACTIVE grant per (user, Group, role) (partial unique index);
 * - a grant is only created for an active Group, and revocation is the one
 *   permitted change: revoked_at/revoked_by set once, after which the row
 *   is immutable and can never become active again -- re-granting inserts
 *   a new row (trigger);
 * - the runtime role cannot DELETE a grant (history; elevations reference
 *   it), and the Group/User it names cannot be deleted from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('school_group_id')->constrained('school_groups')->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('school_group_id');
            // Composite key target for school_elevations' (grant, Group) FK,
            // so an elevation can never name a grant of a different Group.
            $table->unique(['id', 'school_group_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE group_role_assignments
                ADD CONSTRAINT group_role_assignments_no_self_grant CHECK (granted_by_user_id <> user_id),
                ADD CONSTRAINT group_role_assignments_revocation_check
                    CHECK ((revoked_at IS NULL) = (revoked_by_user_id IS NULL))
            SQL);

        DB::statement('CREATE UNIQUE INDEX group_role_assignments_one_active ON group_role_assignments (user_id, school_group_id, role_id) WHERE revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_group_role_assignment() RETURNS trigger AS $$
            DECLARE
                role_scope text;
                group_status text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                    IF role_scope IS DISTINCT FROM 'group' THEN
                        RAISE EXCEPTION
                            'group_role_assignments.role_id must reference a role with scope=group (got %)', role_scope;
                    END IF;
                    SELECT status INTO group_status FROM school_groups WHERE id = NEW.school_group_id FOR SHARE;
                    IF group_status IS DISTINCT FROM 'active' THEN
                        RAISE EXCEPTION 'group_role_assignments: an archived School Group cannot receive a grant';
                    END IF;
                    IF NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'group_role_assignments: a grant cannot be created already revoked';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'group_role_assignments: a revoked grant is immutable and cannot be reactivated';
                END IF;
                IF NEW.revoked_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.school_group_id IS DISTINCT FROM OLD.school_group_id
                    OR NEW.role_id IS DISTINCT FROM OLD.role_id
                    OR NEW.granted_by_user_id IS DISTINCT FROM OLD.granted_by_user_id
                    OR NEW.granted_at IS DISTINCT FROM OLD.granted_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'group_role_assignments: the only permitted change is revocation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_group_role_assignments_guard
                BEFORE INSERT OR UPDATE ON group_role_assignments
                FOR EACH ROW EXECUTE FUNCTION assert_group_role_assignment();
            SQL);

        TenantRls::revokeDelete('group_role_assignments');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_group_role_assignments_guard ON group_role_assignments;
            DROP FUNCTION IF EXISTS assert_group_role_assignment();
            SQL);

        Schema::dropIfExists('group_role_assignments');
    }
};
