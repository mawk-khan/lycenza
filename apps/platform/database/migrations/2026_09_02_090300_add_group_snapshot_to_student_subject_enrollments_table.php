<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1F.1 (architecture doc §10, §11B, §18B) -- two new nullable
     * columns on `student_subject_enrollments`:
     *
     * `student_enrollment_id`: the exact placement (StudentEnrollment)
     * this participation belongs to. Introduced because
     * `(student_id, academic_year_id)` is NOT equivalent to one
     * specific StudentEnrollment -- a Student may be withdrawn and
     * re-enrolled within the same AcademicYear (§0A of the architecture
     * doc). Existing rows remain NULL -- no heuristic backfill is
     * possible (multiple StudentEnrollment rows can exist for one
     * Student/year with no reliable disambiguator, §18B) -- and this
     * migration does not attempt one. Phase 1F.2 will make canonical
     * new writes supply it; this checkpoint ships schema only.
     *
     * `elective_group_id`: a denormalized snapshot of the target
     * SubjectOffering's `elective_group_id` at write time -- the only
     * way a partial unique index can express cross-offering,
     * same-group exclusivity without a join (architecture doc §11).
     * Existing rows remain NULL (no semantic backfill, §18A/§18B) --
     * this is correct, since every existing SubjectOffering is also
     * ungrouped (`elective_group_id IS NULL`) at this checkpoint.
     *
     * Both columns are nullable at the column level for legacy-row
     * compatibility, but are populated together going forward. The
     * composite anchor FK on `student_enrollment_id` is a CRITICAL
     * invariant (architecture doc §11B/§13 of this checkpoint's brief):
     * when non-null, PostgreSQL rejects a `student_enrollment_id` that
     * does not belong to this row's own `student_id`/`academic_year_id`/
     * `school_id` -- closing the cross-Student bypass where a
     * mutual-exclusivity conflict could otherwise be evaluated against
     * an unrelated Student's placement.
     *
     * `elective_group_id`'s own composite FK (against
     * `elective_groups(id, school_id)`) proves only "this is a real
     * group in this School" -- it does NOT, and structurally cannot
     * under PostgreSQL's default MATCH SIMPLE, prove the snapshot
     * equals the referenced SubjectOffering's actual group (a NULL
     * snapshot skips FK checking entirely -- see
     * docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md
     * §0B/§11A for the empirical proof). That equality is proven by the
     * trigger installed in the next migration, not by this FK.
     *
     * The CHECK constraint closes a narrower, same-row gap: a grouped
     * participation can never exist without also recording which
     * placement it belongs to (an "grouped but anchor-less" orphan
     * state), independent of and in addition to the trigger.
     */
    public function up(): void
    {
        Schema::table('student_subject_enrollments', function (Blueprint $table) {
            $table->uuid('student_enrollment_id')->nullable()->after('student_id');
            $table->uuid('elective_group_id')->nullable()->after('subject_offering_id');
            $table->index(['student_enrollment_id']);
            $table->index(['elective_group_id']);

            $table->foreign(
                ['student_enrollment_id', 'school_id', 'student_id', 'academic_year_id'],
                'student_subject_enrollments_placement_anchor_fk',
            )
                ->references(['id', 'school_id', 'student_id', 'academic_year_id'])
                ->on('student_enrollments')
                ->restrictOnDelete();

            $table->foreign(
                ['elective_group_id', 'school_id'],
                'student_subject_enrollments_elective_group_fk',
            )
                ->references(['id', 'school_id'])
                ->on('elective_groups')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE student_subject_enrollments ADD CONSTRAINT student_subject_enrollments_grouped_requires_anchor_check '.
            'CHECK (elective_group_id IS NULL OR student_enrollment_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE student_subject_enrollments DROP CONSTRAINT IF EXISTS student_subject_enrollments_grouped_requires_anchor_check');

        Schema::table('student_subject_enrollments', function (Blueprint $table) {
            $table->dropForeign('student_subject_enrollments_elective_group_fk');
            $table->dropForeign('student_subject_enrollments_placement_anchor_fk');
            $table->dropColumn(['elective_group_id', 'student_enrollment_id']);
        });
    }
};
