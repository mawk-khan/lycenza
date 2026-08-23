<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.4 §14/§15 -- extends the EXISTING Announcement aggregate
     * rather than a second "scheduled_messages" silo (brief §14).
     *
     * `scheduled_at`/`scheduled_by_user_id`: brief's minimal schema.
     * No `claimed_at`/processing-lease column was added -- unlike
     * App\Jobs\ProcessCommunicationDeliveryJob or DeliverWebhookJob
     * (which split "claim" from "do the work" across a job that can
     * itself crash mid-attempt), App\Domain\Communications\Application\
     * AnnouncementService::publish() performs its atomic
     * scheduled/draft -> published claim INSIDE the same single
     * DB::transaction() as the actual publish work. A crash between
     * claim and commit cannot happen -- the transaction either commits
     * whole (published) or nothing persists at all (still `scheduled`,
     * genuinely never claimed) -- so a separate lease/marker column
     * would be dead weight for this checkpoint's design. See
     * docs/communication-hub/PHASE-5A-4-TEMPLATES-SCHEDULING.md
     * ("Failure recovery").
     *
     * `source_template_id`: brief §7 -- a plain informational reference
     * to the communication_templates row an Announcement was created
     * from, if any. Composite FK against communication_templates(id,
     * school_id) (root CLAUDE.md rule 70); `restrictOnDelete()` matches
     * `campus_id` on this same table -- templates are never hard-deleted
     * (deactivated only), so this is a defensive default, not a path
     * this checkpoint's code ever actually exercises.
     *
     * `status` CHECK is widened from ('draft','published','cancelled')
     * to include 'scheduled' -- additive, not destructive (root
     * CLAUDE.md rule 15's "widening later is additive" already
     * anticipated exactly this in the Phase 5A.2 migration's own
     * docblock).
     */
    public function up(): void
    {
        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('audience_type');
            $table->foreignUuid('scheduled_by_user_id')->nullable()->after('scheduled_at')->constrained('users');
            $table->uuid('source_template_id')->nullable()->after('scheduled_by_user_id');

            $table->index(['school_id', 'status', 'scheduled_at']);

            $table->foreign(['source_template_id', 'school_id'], 'ca_source_template_school_foreign')
                ->references(['id', 'school_id'])->on('communication_templates')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_status_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_status_check '.
            "CHECK (status IN ('draft', 'scheduled', 'published', 'cancelled'))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses 'scheduled' --
        // the same assumption every other narrowing rollback in this
        // codebase makes (a local/test-only rollback path, never run
        // against real data with `scheduled` rows present).
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_status_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_status_check '.
            "CHECK (status IN ('draft', 'published', 'cancelled'))"
        );

        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->dropForeign('ca_source_template_school_foreign');
            $table->dropIndex(['school_id', 'status', 'scheduled_at']);
            $table->dropConstrainedForeignId('scheduled_by_user_id');
            $table->dropColumn(['scheduled_at', 'source_template_id']);
        });
    }
};
