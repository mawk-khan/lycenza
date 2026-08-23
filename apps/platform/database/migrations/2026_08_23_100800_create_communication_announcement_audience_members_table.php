<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.2 §9/§24 -- the explicit member list authored for an
     * `individual` audience_type Announcement (empty/unused for
     * `school_wide`). `school_membership_id` is a COMPOSITE foreign key
     * against school_memberships(id, school_id) -- the same structural
     * pattern membership_role_assignments/webhook_subscriptions/every
     * Academic Structure child table already established (root
     * CLAUDE.md rule 70) -- so a cross-School membership id is rejected
     * by the database at INSERT time, not merely filtered at query
     * time (brief §12: "Cross-school explicit recipient IDs are
     * rejected"). This is the AUTHORED list, re-validated for
     * eligibility again at resolve/publish time (§13) since membership
     * status can change between authoring and publishing.
     */
    public function up(): void
    {
        Schema::create('communication_announcement_audience_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->uuid('school_membership_id');
            $table->timestamps();

            $table->unique(['announcement_id', 'school_membership_id'], 'caam_announcement_membership_unique');
            $table->index('school_id');

            // Explicit short names: the auto-generated names for these
            // two composite FKs both truncate to the same 63-character
            // Postgres identifier prefix ("...announcement_id_sch...")
            // and collide.
            $table->foreign(['announcement_id', 'school_id'], 'caam_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();

            $table->foreign(['school_membership_id', 'school_id'], 'caam_membership_school_foreign')
                ->references(['id', 'school_id'])->on('school_memberships')
                ->cascadeOnDelete();
        });

        TenantRls::enable('communication_announcement_audience_members');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcement_audience_members');
        Schema::dropIfExists('communication_announcement_audience_members');
    }
};
