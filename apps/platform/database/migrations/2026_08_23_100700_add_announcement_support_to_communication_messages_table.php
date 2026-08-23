<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.2 §5/§16: an Announcement publishes through the SAME
     * canonical CommunicationMessage table Phase 5A.1 introduced,
     * rather than a second parallel content table (brief §5: "Do not
     * build a second delivery system for broadcasts"). A message now
     * belongs to EXACTLY ONE container -- a Thread (conversation) or an
     * Announcement (broadcast), never both, never neither -- enforced
     * by the CHECK constraint below, not just application code.
     * `message_type` is deliberately left as `text` for announcement
     * messages too (the content shape is unchanged); `announcement_id
     * IS NOT NULL` is what a reader checks to know "this is an
     * announcement", not a new message_type value.
     */
    public function up(): void
    {
        Schema::table('communication_messages', function (Blueprint $table) {
            $table->uuid('announcement_id')->nullable()->after('thread_id');
        });

        DB::statement('ALTER TABLE communication_messages ALTER COLUMN thread_id DROP NOT NULL');

        Schema::table('communication_messages', function (Blueprint $table) {
            $table->index(['announcement_id', 'created_at']);

            $table->foreign(['announcement_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_messages ADD CONSTRAINT communication_messages_exactly_one_container_check '.
            'CHECK (num_nonnulls(thread_id, announcement_id) = 1)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_messages DROP CONSTRAINT communication_messages_exactly_one_container_check');

        Schema::table('communication_messages', function (Blueprint $table) {
            $table->dropForeign(['announcement_id', 'school_id']);
            $table->dropIndex(['announcement_id', 'created_at']);
        });

        DB::statement('ALTER TABLE communication_messages ALTER COLUMN thread_id SET NOT NULL');

        Schema::table('communication_messages', function (Blueprint $table) {
            $table->dropColumn('announcement_id');
        });
    }
};
