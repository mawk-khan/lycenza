<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.10 -- explicit STANDARD/EMERGENCY dispatch mode, same
     * additive-column-plus-CHECK-constraint shape as
     * 2026_08_23_101600_add_requirement_to_communication_announcements_table.php.
     * `default('standard')` means every historical row (and every row
     * written by code that hasn't been touched by this checkpoint) is
     * unambiguously STANDARD -- no backfill changes any existing
     * announcement's semantics, and no historical CRITICAL/REQUIRED
     * announcement is ever reinterpreted as an emergency.
     *
     * `emergency_justification`/`emergency_declared_by_user_id`/
     * `emergency_declared_at` are only ever non-null together, when
     * `dispatch_mode = 'emergency'` -- enforced at the application
     * layer (App\Domain\Communications\Application\AnnouncementService),
     * not by a DB constraint, matching how `scheduled_by_user_id`/
     * `scheduled_at` are already handled for scheduling.
     */
    public function up(): void
    {
        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->string('dispatch_mode')->default('standard')->after('requirement');
            $table->text('emergency_justification')->nullable()->after('dispatch_mode');
            $table->foreignUuid('emergency_declared_by_user_id')->nullable()->after('emergency_justification')->constrained('users');
            $table->timestamp('emergency_declared_at')->nullable()->after('emergency_declared_by_user_id');

            $table->index(['school_id', 'dispatch_mode']);
        });

        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_dispatch_mode_check '.
            "CHECK (dispatch_mode IN ('standard', 'emergency'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_dispatch_mode_check');

        Schema::table('communication_announcements', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'dispatch_mode']);
            $table->dropConstrainedForeignId('emergency_declared_by_user_id');
            $table->dropColumn(['dispatch_mode', 'emergency_justification', 'emergency_declared_at']);
        });
    }
};
