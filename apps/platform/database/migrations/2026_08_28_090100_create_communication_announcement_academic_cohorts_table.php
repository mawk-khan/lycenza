<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5B.3 -- the authored academic-cohort audience definition
     * for `audience_type = grade`/`section`. One row per Announcement
     * (`unique(announcement_id)`) -- an academic-cohort Announcement
     * targets exactly ONE GradeLevel or Section, never a multi-cohort
     * selection (docs/communication-hub/
     * PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md documents this as a
     * deliberate first-generation limitation, not an oversight).
     *
     * `academic_year_id` is ALWAYS stored, even for `section` (where
     * `section_id` alone already structurally implies exactly one
     * AcademicYear via `sections.academic_year_id`) -- brief §17's own
     * "store academic_year_id with Section too... for validation,
     * fingerprints, UI clarity". `grade_level_id`/`section_id` are
     * mutually exclusive (`num_nonnulls = 1`), tied to `cohort_type` by
     * a second CHECK so a row can never claim `cohort_type = 'section'`
     * while actually carrying a `grade_level_id` (or vice versa).
     *
     * `recipient_kind` (`student`/`guardian`) is the projection switch
     * both GradeAudienceResolver and SectionAudienceResolver read to
     * decide whether to resolve the cohort's currently-enrolled
     * Students directly, or project them through
     * GuardianProjectionResolver -- this is what keeps the whole
     * academic-cohort audience space to 2 CommunicationAudienceType
     * cases instead of 4 (brief §5's "composable model... do not
     * duplicate the whole resolver system").
     *
     * Composite FKs against communication_announcements/grade_levels/
     * sections/academic_years(id, school_id) -- the same pattern every
     * other Communications-owned reference table in this lineage
     * already uses; a cross-School GradeLevel/Section/AcademicYear id
     * is rejected at INSERT time, independent of RLS.
     */
    public function up(): void
    {
        Schema::create('communication_announcement_academic_cohorts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->string('cohort_type'); // grade_level|section
            $table->uuid('academic_year_id');
            $table->uuid('grade_level_id')->nullable();
            $table->uuid('section_id')->nullable();
            $table->string('recipient_kind'); // student|guardian
            $table->timestamps();

            $table->unique('announcement_id');
            $table->index('school_id');

            $table->foreign(['announcement_id', 'school_id'], 'caac_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();

            $table->foreign(['academic_year_id', 'school_id'], 'caac_academic_year_school_foreign')
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['grade_level_id', 'school_id'], 'caac_grade_level_school_foreign')
                ->references(['id', 'school_id'])->on('grade_levels')
                ->restrictOnDelete();

            $table->foreign(['section_id', 'school_id'], 'caac_section_school_foreign')
                ->references(['id', 'school_id'])->on('sections')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_exactly_one_cohort_check '.
            'CHECK (num_nonnulls(grade_level_id, section_id) = 1)'
        );
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_check '.
            "CHECK (cohort_type IN ('grade_level', 'section'))"
        );
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_cohort_type_matches_check '.
            "CHECK ((cohort_type = 'grade_level' AND grade_level_id IS NOT NULL AND section_id IS NULL) ".
            "OR (cohort_type = 'section' AND section_id IS NOT NULL AND grade_level_id IS NULL))"
        );
        DB::statement(
            'ALTER TABLE communication_announcement_academic_cohorts ADD CONSTRAINT caac_recipient_kind_check '.
            "CHECK (recipient_kind IN ('student', 'guardian'))"
        );

        TenantRls::enable('communication_announcement_academic_cohorts');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcement_academic_cohorts');
        Schema::dropIfExists('communication_announcement_academic_cohorts');
    }
};
