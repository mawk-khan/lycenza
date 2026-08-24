<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5B.2 -- an explicit, EXPLICIT-ONLY (root CLAUDE.md
     * non-negotiable identity rule) link between a Student/Guardian
     * domain identity and an existing, legitimate SchoolMembership.
     * Never auto-created; see App\Domain\Identity\Application\
     * AccountLinkService, the sole write path.
     *
     * One unified table (not two) -- deliberately, so the two
     * invariants below can BOTH be database-enforced by a single set
     * of partial unique indexes rather than needing a cross-table
     * trigger (the `membership_role_assignments`/
     * `platform_role_assignments` scope-trigger pattern this codebase
     * already has elsewhere, avoided here because a single table is
     * simpler and sufficient):
     *
     *   1. One domain identity has at most one ACTIVE link
     *      (`sgal_one_active_per_student`/`sgal_one_active_per_guardian`).
     *   2. One SchoolMembership has at most one ACTIVE persona link
     *      (`sgal_one_active_per_membership`) -- this single index
     *      covers BOTH "membership already linked to a different
     *      Guardian" AND "membership already linked as a Student"
     *      simultaneously, since both persona types share this one
     *      table. A membership's own STAFF role (school_admin/
     *      principal/...) is unaffected either way -- this table only
     *      ever represents a Student/Guardian domain-identity link
     *      layered on top of a membership, never the membership's
     *      role/capability grants themselves (docs/communication-hub/
     *      PHASE-5B-2-STUDENT-GUARDIAN-ACCOUNT-LINK-INAPP.md
     *      "Staff/Guardian dual-role behavior").
     *
     * `status` (`active`/`revoked`), never a hard DELETE on unlink --
     * mirrors `school_memberships.status` itself (which is also never
     * deleted, only status-flagged) rather than the append-only-ledger
     * pattern this codebase reserves for actual audit trails
     * (`communication_announcement_recipients`/
     * `communication_delivery_attempts`). This table is current
     * operational state, not a historical ledger -- the ledger is
     * `school_audit_events` (student.account_linked/unlinked,
     * guardian.account_linked/unlinked), written by AccountLinkService
     * alongside every link/unlink.
     *
     * `num_nonnulls(student_id, guardian_id) = 1` is the database-level
     * "exactly one persona per row" guarantee, the same pattern
     * `communication_announcement_domain_audience_members` (Phase
     * 5B.1) already established for the identical "exactly one typed
     * target" shape.
     *
     * Composite FKs against students(id, school_id)/guardians(id,
     * school_id)/school_memberships(id, school_id) -- `school_memberships`
     * already carries `unique(id, school_id)` specifically "to enable
     * composite FKs from tenant-owned children" (its own migration's
     * docblock), so no change to that table is needed.
     */
    public function up(): void
    {
        Schema::create('student_guardian_account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id')->nullable();
            $table->uuid('guardian_id')->nullable();
            $table->uuid('school_membership_id');
            $table->string('status')->default('active'); // active|revoked
            $table->foreignUuid('linked_by_user_id')->constrained('users');
            $table->timestamp('linked_at');
            $table->foreignUuid('unlinked_by_user_id')->nullable()->constrained('users');
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('school_membership_id');

            $table->foreign(['student_id', 'school_id'], 'sgal_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['guardian_id', 'school_id'], 'sgal_guardian_school_foreign')
                ->references(['id', 'school_id'])->on('guardians')
                ->cascadeOnDelete();

            $table->foreign(['school_membership_id', 'school_id'], 'sgal_membership_school_foreign')
                ->references(['id', 'school_id'])->on('school_memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE student_guardian_account_links ADD CONSTRAINT sgal_exactly_one_persona_check '.
            'CHECK (num_nonnulls(student_id, guardian_id) = 1)'
        );
        DB::statement(
            'ALTER TABLE student_guardian_account_links ADD CONSTRAINT sgal_status_check '.
            "CHECK (status IN ('active', 'revoked'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX sgal_one_active_per_student ON student_guardian_account_links (student_id) '.
            "WHERE status = 'active' AND student_id IS NOT NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX sgal_one_active_per_guardian ON student_guardian_account_links (guardian_id) '.
            "WHERE status = 'active' AND guardian_id IS NOT NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX sgal_one_active_per_membership ON student_guardian_account_links (school_membership_id) '.
            "WHERE status = 'active'"
        );

        TenantRls::enable('student_guardian_account_links');
    }

    public function down(): void
    {
        TenantRls::disable('student_guardian_account_links');
        Schema::dropIfExists('student_guardian_account_links');
    }
};
