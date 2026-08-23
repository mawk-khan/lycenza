<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5A.8 §46 -- `communication_threads.last_activity_at` is
     * the sort key CommunicationInboxReadModel (and Phase 5A.7's
     * conversation list) orders by. Like Phase 5A.7's own
     * `communication_messages.created_at`/`communication_thread_participants.last_read_at`
     * precision fix, the original whole-second precision made two
     * threads that received activity within the same wall-clock
     * second (trivial under fast/automated creation, and a real
     * possibility for two humans messaging in quick succession)
     * compare as simultaneous, leaving their relative order in a
     * mixed Inbox/Conversations list unspecified rather than
     * genuinely most-recent-first. Purely a precision widening -- no
     * data is rounded away or lost.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN last_activity_at TYPE timestamp(6)');
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN created_at TYPE timestamp(6)');
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN updated_at TYPE timestamp(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN updated_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN created_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE communication_threads ALTER COLUMN last_activity_at TYPE timestamp(0)');
    }
};
