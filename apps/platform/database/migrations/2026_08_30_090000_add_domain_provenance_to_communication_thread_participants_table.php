<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5D.1 -- the authenticated Thread participant endpoint stays
     * `user_id` (a SchoolMembership-backed User, unchanged from Phase
     * 5A.1) but MAY now carry optional Student/Guardian domain
     * provenance layered on top of it, mirroring the exact "one
     * authenticated endpoint, optional typed domain metadata" shape
     * `student_guardian_account_links` already established. This is
     * NOT a second way to identify a participant -- a row is still
     * found/joined by `(thread_id, user_id)` exactly as before;
     * `participant_kind`/`guardian_id`/`student_id` only record WHICH
     * capacity that already-authenticated User joined in, for
     * authorization/audit purposes (brief §8/§9/§21).
     *
     * `participant_kind` is a real column, not derived from which id is
     * non-null, so a query can filter "every Guardian-context
     * participant" without a `CASE` expression; the CHECK constraint
     * below is what keeps it from ever drifting out of sync with
     * `guardian_id`/`student_id`.
     *
     * Deliberately `RESTRICT` on delete for both composite FKs -- unlike
     * `student_guardian_account_links` (whose CASCADE is correct
     * because that table represents CURRENT link state, not history),
     * this table is historical Thread participation
     * (`communication_thread_participants`' own creating migration:
     * "left_at is set, not deleted, so a departed participant's message
     * history is preserved"). A Guardian/Student row is deactivated,
     * never deleted (root CLAUDE.md rule 73 extends the same
     * `active`/`inactive` treatment to Guardian/Student identity), so
     * this FK direction should never actually fire in practice -- it
     * exists as a guard against ever silently losing conversation
     * history to an unrelated future hard-delete.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE communication_thread_participants ADD COLUMN participant_kind varchar(255) NOT NULL DEFAULT 'membership'");
        DB::statement('ALTER TABLE communication_thread_participants ADD COLUMN guardian_id uuid NULL');
        DB::statement('ALTER TABLE communication_thread_participants ADD COLUMN student_id uuid NULL');

        DB::statement(
            'ALTER TABLE communication_thread_participants ADD CONSTRAINT ctp_guardian_school_foreign '.
            'FOREIGN KEY (guardian_id, school_id) REFERENCES guardians (id, school_id) ON DELETE RESTRICT'
        );
        DB::statement(
            'ALTER TABLE communication_thread_participants ADD CONSTRAINT ctp_student_school_foreign '.
            'FOREIGN KEY (student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE communication_thread_participants ADD CONSTRAINT ctp_participant_kind_check '.
            "CHECK (participant_kind IN ('membership', 'guardian', 'student'))"
        );

        DB::statement(
            'ALTER TABLE communication_thread_participants ADD CONSTRAINT ctp_kind_provenance_consistency_check '.
            'CHECK ('.
            "(participant_kind = 'guardian' AND guardian_id IS NOT NULL AND student_id IS NULL) OR ".
            "(participant_kind = 'student' AND student_id IS NOT NULL AND guardian_id IS NULL) OR ".
            "(participant_kind = 'membership' AND guardian_id IS NULL AND student_id IS NULL)".
            ')'
        );

        DB::statement('CREATE INDEX ctp_guardian_id_index ON communication_thread_participants (guardian_id) WHERE guardian_id IS NOT NULL');
        DB::statement('CREATE INDEX ctp_student_id_index ON communication_thread_participants (student_id) WHERE student_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ctp_student_id_index');
        DB::statement('DROP INDEX IF EXISTS ctp_guardian_id_index');
        DB::statement('ALTER TABLE communication_thread_participants DROP CONSTRAINT ctp_kind_provenance_consistency_check');
        DB::statement('ALTER TABLE communication_thread_participants DROP CONSTRAINT ctp_participant_kind_check');
        DB::statement('ALTER TABLE communication_thread_participants DROP CONSTRAINT ctp_student_school_foreign');
        DB::statement('ALTER TABLE communication_thread_participants DROP CONSTRAINT ctp_guardian_school_foreign');
        DB::statement('ALTER TABLE communication_thread_participants DROP COLUMN student_id');
        DB::statement('ALTER TABLE communication_thread_participants DROP COLUMN guardian_id');
        DB::statement('ALTER TABLE communication_thread_participants DROP COLUMN participant_kind');
    }
};
