<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5A.12 §16 -- widens the Announcement status CHECK from
     * ('draft','scheduled','published','cancelled') to include
     * 'pending_approval', 'approved', 'rejected'. Additive only (root
     * CLAUDE.md rule 15's "widening later is additive", already
     * anticipated by the Phase 5A.2/5A.4 migrations' own docblocks).
     * No new columns -- the announcement's own `status` column remains
     * the single source of truth for "what state is this Announcement
     * in right now" (brief §16's "avoid duplicating approval state
     * ... without a clear source of truth"); WHO requested/decided,
     * WHEN, and the reviewed-content fingerprint live in the new
     * `communication_approval_requests` table (see that migration).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_status_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_status_check '.
            "CHECK (status IN ('draft', 'pending_approval', 'approved', 'rejected', 'scheduled', 'published', 'cancelled'))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses one of the new
        // values -- the same assumption every other narrowing rollback
        // in this codebase makes (root CLAUDE.md rule 10).
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_status_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_status_check '.
            "CHECK (status IN ('draft', 'scheduled', 'published', 'cancelled'))"
        );
    }
};
