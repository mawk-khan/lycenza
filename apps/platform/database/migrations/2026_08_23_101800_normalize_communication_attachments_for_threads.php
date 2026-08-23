<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.7 §6 -- the smallest backwards-compatible normalization
     * of `communication_attachments` needed to support conversation-
     * message attachments without a `conversation_attachments`/
     * `thread_attachments` parallel silo (brief §5).
     *
     * `communication_announcement_id` was NOT NULL in Phase 5A.6
     * (every attachment originated from the Announcement composer,
     * the only pre-message-existence editable surface that existed
     * then). CommunicationMessageService::send() has no draft/edit
     * phase at all -- a conversation reply is created and complete in
     * one call -- but the OWNING THREAD already exists before any
     * given message is composed (Phase 5A.1's CommunicationHubController::store()
     * creates an empty thread first; every message, including a
     * thread's first, is sent afterward via the same reply composer --
     * see the phase doc's "Conversation creation" section for why this
     * checkpoint deliberately did not collapse thread-creation and
     * first-message-send into one atomic step). A pending conversation
     * attachment is therefore uploaded against the THREAD, exactly
     * mirroring how a pending announcement attachment is uploaded
     * against the ANNOUNCEMENT -- both are the stable pre-message
     * owner for their respective flow.
     *
     * `communication_announcement_id` becomes NULLABLE; a NEW nullable
     * `communication_thread_id` (composite FK to communication_threads(id,
     * school_id), cascade delete) is added; a CHECK constraint enforces
     * EXACTLY ONE of the two is ever set, so every attachment still has
     * exactly one unambiguous stable pre-message owner. `communication_message_id`
     * is untouched -- still nullable, still the eventual channel-
     * rendering FK backfilled once a real CommunicationMessage exists,
     * for BOTH flows now.
     *
     * Every existing Phase 5A.6 row already has `communication_announcement_id`
     * set and `communication_thread_id` NULL, so the new CHECK
     * constraint is satisfied by 100% of existing data without a
     * backfill -- this migration changes no data, only the schema.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_attachments ALTER COLUMN communication_announcement_id DROP NOT NULL');

        Schema::table('communication_attachments', function (Blueprint $table) {
            $table->uuid('communication_thread_id')->nullable()->after('communication_announcement_id');
            $table->index('communication_thread_id');

            $table->foreign(['communication_thread_id', 'school_id'], 'comm_attachments_thread_school_foreign')
                ->references(['id', 'school_id'])->on('communication_threads')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_attachments ADD CONSTRAINT communication_attachments_one_parent_check '.
            'CHECK (num_nonnulls(communication_announcement_id, communication_thread_id) = 1)'
        );

        // TenantRls policies are per-table, not per-column -- unaffected
        // by this column change, so no TenantRls::enable() re-run is
        // needed here.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_attachments DROP CONSTRAINT communication_attachments_one_parent_check');

        Schema::table('communication_attachments', function (Blueprint $table) {
            $table->dropForeign('comm_attachments_thread_school_foreign');
            $table->dropColumn('communication_thread_id');
        });

        DB::statement('ALTER TABLE communication_attachments ALTER COLUMN communication_announcement_id SET NOT NULL');
    }
};
