<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central/platform data: Platform Super Admin / platform-scoped
     * role grants. Deliberately separate from school_memberships /
     * membership_role_assignments -- a platform capability never
     * implicitly grants a school.* capability (section 12/30: no
     * invisible "see every tenant row" mode). The trigger below is a
     * database-enforced guarantee (not just application code
     * discipline) that only scope='platform' roles can ever be
     * assigned here -- see docs/security/AUTHORIZATION.md.
     */
    public function up(): void
    {
        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_platform_role_assignment_scope()
            RETURNS trigger AS $$
            DECLARE
                role_scope text;
            BEGIN
                SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                IF role_scope IS DISTINCT FROM 'platform' THEN
                    RAISE EXCEPTION
                        'platform_role_assignments.role_id must reference a role with scope=platform (got %)',
                        role_scope;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_platform_role_assignments_scope
            BEFORE INSERT OR UPDATE ON platform_role_assignments
            FOR EACH ROW EXECUTE FUNCTION assert_platform_role_assignment_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_platform_role_assignments_scope ON platform_role_assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_platform_role_assignment_scope()');
        Schema::dropIfExists('platform_role_assignments');
    }
};
