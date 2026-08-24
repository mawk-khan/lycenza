<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.1 -- widens the immutable resolved-audience snapshot
     * (`communication_announcement_recipients`, Phase 5A.2 §10) to
     * represent a Student or Guardian logical recipient, not only a
     * SchoolMembership one. Purely additive: `user_id`/
     * `school_membership_id` become nullable (every EXISTING row keeps
     * both set -- no historical row is rewritten, root CLAUDE.md rule
     * 6/36's backward-compatibility requirement), and two new nullable
     * typed columns are added with the SAME composite-tenant-FK pattern
     * `communication_announcement_domain_audience_members` (this
     * checkpoint's own new table) just established.
     *
     * `num_nonnulls(user_id, student_id, guardian_id) = 1` is the
     * database-level "exactly one party per snapshot row" guarantee.
     * The existing `car_announcement_user_unique` unique index is left
     * untouched (Postgres treats every NULL as distinct, so it already
     * tolerates arbitrarily many Student/Guardian rows with
     * `user_id IS NULL` without weakening the User-dedup guarantee it
     * already provided); two new unique indexes add the identical
     * per-Announcement dedup guarantee for the two new party types.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcement_recipients ALTER COLUMN school_membership_id DROP NOT NULL');
        DB::statement('ALTER TABLE communication_announcement_recipients ALTER COLUMN user_id DROP NOT NULL');

        DB::statement('ALTER TABLE communication_announcement_recipients ADD COLUMN student_id uuid NULL');
        DB::statement('ALTER TABLE communication_announcement_recipients ADD COLUMN guardian_id uuid NULL');

        DB::statement(
            'ALTER TABLE communication_announcement_recipients ADD CONSTRAINT car_student_school_foreign '.
            'FOREIGN KEY (student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT'
        );
        DB::statement(
            'ALTER TABLE communication_announcement_recipients ADD CONSTRAINT car_guardian_school_foreign '.
            'FOREIGN KEY (guardian_id, school_id) REFERENCES guardians (id, school_id) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE communication_announcement_recipients ADD CONSTRAINT car_exactly_one_party_check '.
            'CHECK (num_nonnulls(user_id, student_id, guardian_id) = 1)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX car_announcement_student_unique ON communication_announcement_recipients (announcement_id, student_id)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX car_announcement_guardian_unique ON communication_announcement_recipients (announcement_id, guardian_id)'
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses a Student/Guardian
        // party (root CLAUDE.md rule 10) -- dropping NOT NULL back on
        // user_id would otherwise fail against those rows anyway.
        DB::statement('DROP INDEX car_announcement_guardian_unique');
        DB::statement('DROP INDEX car_announcement_student_unique');
        DB::statement('ALTER TABLE communication_announcement_recipients DROP CONSTRAINT car_exactly_one_party_check');
        DB::statement('ALTER TABLE communication_announcement_recipients DROP CONSTRAINT car_guardian_school_foreign');
        DB::statement('ALTER TABLE communication_announcement_recipients DROP CONSTRAINT car_student_school_foreign');
        DB::statement('ALTER TABLE communication_announcement_recipients DROP COLUMN guardian_id');
        DB::statement('ALTER TABLE communication_announcement_recipients DROP COLUMN student_id');
        DB::statement('ALTER TABLE communication_announcement_recipients ALTER COLUMN user_id SET NOT NULL');
        DB::statement('ALTER TABLE communication_announcement_recipients ALTER COLUMN school_membership_id SET NOT NULL');
    }
};
