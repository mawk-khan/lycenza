<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5A.7 §21 -- unread derivation compares
     * `communication_thread_participants.last_read_at` against
     * `communication_messages.created_at` with a strict `>`. Both
     * columns were created via Laravel's default `timestamp()`/
     * `timestamps()` precision (whole seconds, no fractional part) in
     * Phase 5A.1 -- fine for display, but two events genuinely less
     * than a second apart (a mark-read immediately followed by a new
     * message, or two rapid replies) previously could not be ordered
     * at all, making a genuinely newer message compare as "not after"
     * a same-second read cursor. Bumping to microsecond precision
     * fixes this at the source rather than working around it in
     * application logic. Purely a precision widening -- no data is
     * rounded away or lost; existing whole-second values remain valid
     * timestamp(6) values.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_messages ALTER COLUMN created_at TYPE timestamp(6)');
        DB::statement('ALTER TABLE communication_messages ALTER COLUMN updated_at TYPE timestamp(6)');
        DB::statement('ALTER TABLE communication_thread_participants ALTER COLUMN last_read_at TYPE timestamp(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_thread_participants ALTER COLUMN last_read_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE communication_messages ALTER COLUMN updated_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE communication_messages ALTER COLUMN created_at TYPE timestamp(0)');
    }
};
