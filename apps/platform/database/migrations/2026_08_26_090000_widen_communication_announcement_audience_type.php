<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.1 -- widens the Announcement audience_type CHECK from
     * ('individual','school_wide') to include 'student', 'guardian',
     * 'guardians_of_students', exactly the same additive-CHECK-widen
     * shape the Phase 5A.12 approval-states migration already
     * established. See
     * App\Domain\Communications\Domain\CommunicationAudienceType and
     * docs/communication-hub/PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students'))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses one of the new
        // values (root CLAUDE.md rule 10, same assumption every other
        // narrowing rollback in this domain already makes).
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide'))"
        );
    }
};
