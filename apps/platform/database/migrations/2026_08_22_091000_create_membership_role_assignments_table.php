<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned data: which role(s) a School membership has WITHIN
     * that School. RLS-protected so a school's role assignments can
     * never be read or written outside that School's context, even by
     * a bug that forgets application-layer scoping (ADR 0004 Layer 2).
     *
     * Section 34 protection: `school_membership_id` and `school_id` are
     * tied together by a composite foreign key against
     * school_memberships(id, school_id) -- it is a foreign-key
     * violation, not just an application bug, to insert a row whose
     * school_id doesn't match its own school_membership_id's school_id.
     *
     * Section 18/51 protection: the trigger below is a database-level
     * guarantee that only scope='school' roles can be assigned here --
     * mirrors platform_role_assignments' equivalent trigger in reverse.
     */
    public function up(): void
    {
        Schema::create('membership_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('school_membership_id');
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['school_membership_id', 'role_id']);
            $table->index('school_id');

            $table->foreign(['school_membership_id', 'school_id'])
                ->references(['id', 'school_id'])->on('school_memberships')
                ->cascadeOnDelete();
        });

        TenantRls::enable('membership_role_assignments');

        DB::unprepared(<<<'SQL'
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

            CREATE TRIGGER trg_membership_role_assignments_scope
            BEFORE INSERT OR UPDATE ON membership_role_assignments
            FOR EACH ROW EXECUTE FUNCTION assert_membership_role_assignment_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_membership_role_assignments_scope ON membership_role_assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_membership_role_assignment_scope()');
        TenantRls::disable('membership_role_assignments');
        Schema::dropIfExists('membership_role_assignments');
    }
};
