<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5C.1 -- widens `communication_announcement_academic_cohorts`
     * (Phase 5B.3) with a third mutually-exclusive cohort reference,
     * `subject_offering_id`, for `cohort_type = 'subject_offering'`.
     * Additive only: existing `grade_level`/`section` rows are
     * untouched, and the widened CHECK constraints preserve their exact
     * existing behavior for those two cohort_type values while adding a
     * third branch. See the creating migration's docblock
     * (2026_08_28_090100_...) for the table's overall shape/rationale,
     * which this migration does not repeat.
     *
     * `academic_year_id` remains required for a SubjectOffering cohort
     * too, for the SAME defense-in-depth reason Section already stores
     * it despite `subject_offerings.academic_year_id` alone already
     * implying it -- see
     * App\Domain\Communications\Application\AnnouncementService::syncAcademicCohort(),
     * which cross-validates the caller-supplied academic_year_id
     * against the SubjectOffering's own.
     *
     * Composite FK to `subject_offerings(id, school_id)` follows the
     * exact same same-School-at-INSERT-time pattern as the
     * grade_level_id/section_id FKs on this table.
     */
    public function up(): void
    {
        Schema::table('communication_announcement_academic_cohorts', function (Blueprint $table) {
            $table->uuid('subject_offering_id')->nullable()->after('section_id');

            $table->foreign(['subject_offering_id', 'school_id'], 'caac_subject_offering_school_foreign')
                ->references(['id', 'school_id'])->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_exactly_one_cohort_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_exactly_one_cohort_check '.
            'CHECK (num_nonnulls(grade_level_id, section_id, subject_offering_id) = 1)'
        );

        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_cohort_type_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_check '.
            "CHECK (cohort_type IN ('grade_level', 'section', 'subject_offering'))"
        );

        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_cohort_type_matches_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_matches_check '.
            "CHECK ((cohort_type = 'grade_level' AND grade_level_id IS NOT NULL AND section_id IS NULL AND subject_offering_id IS NULL) ".
            "OR (cohort_type = 'section' AND section_id IS NOT NULL AND grade_level_id IS NULL AND subject_offering_id IS NULL) ".
            "OR (cohort_type = 'subject_offering' AND subject_offering_id IS NOT NULL AND grade_level_id IS NULL AND section_id IS NULL))"
        );
    }

    public function down(): void
    {
        // Reversible only while no row actually uses cohort_type =
        // 'subject_offering' (root CLAUDE.md rule 10).
        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_cohort_type_matches_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_matches_check '.
            "CHECK ((cohort_type = 'grade_level' AND grade_level_id IS NOT NULL AND section_id IS NULL) ".
            "OR (cohort_type = 'section' AND section_id IS NOT NULL AND grade_level_id IS NULL))"
        );

        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_cohort_type_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_check '.
            "CHECK (cohort_type IN ('grade_level', 'section'))"
        );

        DB::statement('ALTER TABLE communication_announcement_academic_cohorts DROP CONSTRAINT caac_exactly_one_cohort_check');
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_exactly_one_cohort_check '.
            'CHECK (num_nonnulls(grade_level_id, section_id) = 1)'
        );

        Schema::table('communication_announcement_academic_cohorts', function (Blueprint $table) {
            $table->dropForeign('caac_subject_offering_school_foreign');
            $table->dropColumn('subject_offering_id');
        });
    }
};
