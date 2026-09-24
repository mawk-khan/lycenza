<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.5 (ADR 0045 sections 8 and 13): a School Group is archived,
 * never deleted.
 *
 * - `school_groups.status` is `active` or `archived`.
 * - Deleting a Group no longer cascades away its School membership: the
 *   Group side of `school_group_members` is RESTRICT, and the runtime role
 *   cannot DELETE a Group at all. Grants and elevations that reference a
 *   Group (later migrations) stay referentially valid.
 *
 * The School side (`school_group_members.school_id` ON DELETE CASCADE) is
 * deliberately unchanged: School deletion is D11 and already unsafe for
 * every School foreign key (PHASE-0N-READINESS.md section 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_groups', function (Blueprint $table) {
            $table->string('status')->default('active')->after('slug');
        });

        DB::statement("ALTER TABLE school_groups ADD CONSTRAINT school_groups_status_check CHECK (status IN ('active', 'archived'))");

        Schema::table('school_group_members', function (Blueprint $table) {
            $table->dropForeign(['school_group_id']);
            $table->foreign('school_group_id')->references('id')->on('school_groups')->restrictOnDelete();
        });

        TenantRls::revokeDelete('school_groups');
    }

    public function down(): void
    {
        DB::statement('GRANT DELETE ON school_groups TO school_os_app');

        Schema::table('school_group_members', function (Blueprint $table) {
            $table->dropForeign(['school_group_id']);
            $table->foreign('school_group_id')->references('id')->on('school_groups')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE school_groups DROP CONSTRAINT IF EXISTS school_groups_status_check');

        Schema::table('school_groups', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
