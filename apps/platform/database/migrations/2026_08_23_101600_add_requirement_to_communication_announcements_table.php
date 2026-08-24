<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.5 §7/§9 -- `requirement` is deliberately a SEPARATE
     * column from `priority` (already on this table since Phase 5A.2)
     * -- CRITICAL priority does not imply REQUIRED delivery, and
     * NORMAL priority does not imply OPTIONAL. Priority answers "how
     * important is this," requirement answers "may recipient
     * preferences suppress an optional secondary channel." Defaults to
     * `'optional'` so every existing/newly-created Announcement keeps
     * today's behavior unless a `communications.manage`-capable sender
     * explicitly marks it required
     * (App\Domain\Communications\Http\Controllers\AnnouncementController::validateComposer()).
     */
    public function up(): void
    {
        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->string('requirement')->default('optional')->after('priority');
        });

        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_requirement_check '.
            "CHECK (requirement IN ('optional', 'required'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_requirement_check');

        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->dropColumn('requirement');
        });
    }
};
