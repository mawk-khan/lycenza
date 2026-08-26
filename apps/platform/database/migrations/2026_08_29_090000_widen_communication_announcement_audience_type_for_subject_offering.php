<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5C.1 -- widens the Announcement audience_type CHECK to add
     * 'subject_offering', the same additive-CHECK-widen shape every
     * prior audience-type addition in this lineage has already used
     * (Phase 5B.1's Student/Guardian/GuardiansOfStudents widen; Phase
     * 5B.3's Grade/Section widen). See
     * App\Domain\Communications\Domain\CommunicationAudienceType and
     * docs/communication-hub/PHASE-5C-1-SUBJECT-OFFERING-AUDIENCES.md.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students', 'grade', 'section', 'subject_offering'))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses 'subject_offering'
        // (root CLAUDE.md rule 10).
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students', 'grade', 'section'))"
        );
    }
};
