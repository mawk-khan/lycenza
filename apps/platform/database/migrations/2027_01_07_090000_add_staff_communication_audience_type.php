<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.9D-1 (ADR 0076 §73, PV1-10) -- widens the Announcement audience_type
     * CHECK to add 'staff', the same additive widen every prior audience type
     * used (Phase 5B.1, 5B.3, 5C.1). Existing rows already satisfy it.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'staff', 'student', 'guardian', 'guardians_of_students', 'grade', 'section', 'subject_offering'))"
        );
    }

    /**
     * Reversible only while no announcement uses 'staff' (rule 10): an
     * announcement is history and is never rewritten to roll a migration back.
     */
    public function down(): void
    {
        if (DB::table('communication_announcements')->where('audience_type', 'staff')->exists()) {
            throw new RuntimeException("communication_announcements holds 'staff' audiences; they are history and are never rewritten to roll back.");
        }
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'student', 'guardian', 'guardians_of_students', 'grade', 'section', 'subject_offering'))"
        );
    }
};
