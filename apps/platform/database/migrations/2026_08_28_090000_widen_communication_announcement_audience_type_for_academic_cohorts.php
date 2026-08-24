<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.3 -- widens the Announcement audience_type CHECK to add
     * 'grade'/'section', the same additive-CHECK-widen shape every
     * prior audience-type addition in this lineage has already used
     * (Phase 5A.12's approval-states migration; Phase 5B.1's own
     * widen-for-Student/Guardian/GuardiansOfStudents migration). See
     * App\Domain\Communications\Domain\CommunicationAudienceType and
     * docs/communication-hub/PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students', 'grade', 'section'))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses one of the new
        // values (root CLAUDE.md rule 10).
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students'))"
        );
    }
};
