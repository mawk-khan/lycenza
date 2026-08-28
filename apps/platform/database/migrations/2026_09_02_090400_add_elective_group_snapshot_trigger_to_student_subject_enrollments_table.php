<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 1F.1 / Phase 1F.0B (architecture doc §11A) -- closes the
     * empirically-proven NULL-bypass gap a composite FK cannot close.
     * Under PostgreSQL's default MATCH SIMPLE, a FOREIGN KEY check is
     * skipped entirely whenever ANY referencing column is NULL --
     * proven directly (isolated container, no repository schema
     * touched) in the architecture doc's §0B: a
     * `student_subject_enrollments` row with `elective_group_id = NULL`
     * against a grouped SubjectOffering was accepted, and two such
     * NULL-snapshotted rows for the SAME StudentEnrollment targeting
     * two DIFFERENT Offerings in the SAME group were BOTH accepted --
     * a real, working exploit of a composite-FK-only design.
     *
     * This trigger mirrors the exact, already-precedented shape of
     * `assert_membership_role_assignment_scope()`
     * (`2026_08_22_091000_create_membership_role_assignments_table.php`)
     * -- `CREATE OR REPLACE FUNCTION` + `BEFORE INSERT OR UPDATE ...
     * FOR EACH ROW` + `IS DISTINCT FROM` (NULL-safe) + `RAISE
     * EXCEPTION` -- applied to a structurally identical problem: two
     * different write paths (this table, and the future Phase 1F.3
     * `subject_offerings.elective_group_id` configuration write)
     * touching two different tables, where an ordinary FK cannot
     * express "these columns must equal another row's column".
     *
     * The target SubjectOffering is resolved by BOTH `id` AND
     * `school_id` (never an unqualified lookup) -- the same tenant-
     * safety discipline every composite FK in this codebase already
     * follows, so the trigger cannot be confused about which School's
     * Offering it is validating against, independent of RLS.
     *
     * Narrow by design (checkpoint brief §9/§23): this trigger proves
     * ONE relational snapshot-equality fact only -- it is never a
     * lifecycle/business decision, never touches Student PII (only
     * UUIDs appear in its error message), and does not replace the
     * Phase 1F.0A `SELECT ... FOR UPDATE` SubjectOffering lock a future
     * Phase 1F.2 write path must still take before deriving the
     * snapshot values it inserts -- see the architecture doc's §16A for
     * why both mechanisms are complementary, not redundant.
     *
     * The partial unique index below is the DB-enforced race backstop
     * for cross-offering, same-group, same-StudentEnrollment
     * exclusivity (architecture doc §4) -- installed in the same
     * migration as the trigger because both are meaningless without
     * the columns the previous migration just added, and this is the
     * exact "5. install snapshot trigger + group partial unique" slice
     * from this checkpoint's own planned migration sequence.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_student_subject_enrollment_elective_group_snapshot()
            RETURNS trigger AS $$
            DECLARE
                offering_group_id uuid;
            BEGIN
                SELECT elective_group_id INTO offering_group_id
                FROM subject_offerings
                WHERE id = NEW.subject_offering_id AND school_id = NEW.school_id;

                IF NEW.elective_group_id IS DISTINCT FROM offering_group_id THEN
                    RAISE EXCEPTION
                        'student_subject_enrollments.elective_group_id (%) must match subject_offerings.elective_group_id (%) for subject_offering_id %',
                        NEW.elective_group_id, offering_group_id, NEW.subject_offering_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_student_subject_enrollments_elective_group_snapshot
            BEFORE INSERT OR UPDATE ON student_subject_enrollments
            FOR EACH ROW EXECUTE FUNCTION assert_student_subject_enrollment_elective_group_snapshot();
        SQL);

        DB::statement(
            'CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_elective_group '.
            'ON student_subject_enrollments (student_enrollment_id, elective_group_id) '.
            "WHERE status = 'active' AND elective_group_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS student_subject_enrollments_one_active_per_elective_group');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_student_subject_enrollments_elective_group_snapshot ON student_subject_enrollments');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_student_subject_enrollment_elective_group_snapshot()');
    }
};
