<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RES.2 (ADR 0068 §6, §10, §12, §19; RES-L0 determination 2026-10-07):
     * internal StudentMark -- Highly Sensitive children's educational data,
     * recorded by authorised administrative staff only, development only
     * (production: RES-L1).
     *
     * - `student_marks`: one current mark per Student x ExaminationPaper.
     *   Status `present` (a value 0..max_marks) / `absent` / `exempt` (no
     *   value); no remark, grade, percentage, pass/fail, rank, publication or
     *   visibility column. It keeps the context it was written under:
     *   - the P3 placement (pinned to the same Student and year) and, for an
     *     elective, the subject-enrollment row (ADR 0068 §5, RES.1);
     *   - the eligibility SOURCE (`required` / `elective`), snapshotted because
     *     `subject_offerings.is_required` is mutable without history (§18.3);
     *   - the ADR 0038 processing authorization, structurally the same
     *     Student's `academic_records` grant (the registry's own context key);
     *   - the actor of the latest write.
     *   `version` increments by exactly one per write (optimistic
     *   concurrency). Runtime DELETE is revoked.
     * - `student_mark_revisions`: insert-only value history of EVERY write,
     *   pre-lock included (§19.3 a). Written only by the marks trigger -- a
     *   direct insert is refused -- so no write can skip it. Values live here,
     *   never in audit metadata. Runtime UPDATE and DELETE are revoked.
     * - Once a paper has marks, its `max_marks` and `scheduled_on` (the P3
     *   date) are frozen, so a recorded mark's meaning never changes.
     * - Retention: anchored and delete-guarded; catalogued
     *   `policy_unresolved` until RES-L8. No period is chosen.
     */
    public function up(): void
    {
        Schema::create('student_marks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('examination_paper_id');
            $table->uuid('academic_year_id');
            $table->uuid('student_id');
            $table->uuid('student_enrollment_id');
            $table->string('eligibility_source');
            $table->uuid('student_subject_enrollment_id')->nullable();
            $table->uuid('processing_authorization_id');
            $table->string('processing_purpose')->default('academic_records');
            $table->string('status');
            $table->decimal('value', 6, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'examination_paper_id', 'student_id'], 'student_marks_one_per_student_paper');

            $table->foreign(['examination_paper_id', 'school_id'], 'student_marks_paper_fk')
                ->references(['id', 'school_id'])->on('examination_papers')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'student_marks_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['student_id', 'school_id'], 'student_marks_student_fk')
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['student_enrollment_id', 'school_id', 'student_id', 'academic_year_id'], 'student_marks_placement_fk')
                ->references(['id', 'school_id', 'student_id', 'academic_year_id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['student_subject_enrollment_id', 'school_id'], 'student_marks_elective_fk')
                ->references(['id', 'school_id'])->on('student_subject_enrollments')->restrictOnDelete();
            $table->foreign(['processing_authorization_id', 'school_id', 'student_id', 'processing_purpose'], 'student_marks_authorization_fk')
                ->references(['id', 'school_id', 'student_id', 'purpose'])->on('student_processing_authorizations')->restrictOnDelete();
        });

        Schema::create('student_mark_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_mark_id');
            $table->unsignedInteger('revision');
            $table->string('previous_status')->nullable();
            $table->decimal('previous_value', 6, 2)->nullable();
            $table->string('new_status');
            $table->decimal('new_value', 6, 2)->nullable();
            $table->uuid('student_enrollment_id');
            $table->string('eligibility_source');
            $table->uuid('student_subject_enrollment_id')->nullable();
            $table->uuid('processing_authorization_id');
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->unique(['id', 'school_id']);
            $table->unique(['student_mark_id', 'revision'], 'student_mark_revisions_one_per_version');

            $table->foreign(['student_mark_id', 'school_id'], 'student_mark_revisions_mark_fk')
                ->references(['id', 'school_id'])->on('student_marks')->restrictOnDelete();
            $table->foreign(['student_enrollment_id', 'school_id'], 'student_mark_revisions_placement_fk')
                ->references(['id', 'school_id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['student_subject_enrollment_id', 'school_id'], 'student_mark_revisions_elective_fk')
                ->references(['id', 'school_id'])->on('student_subject_enrollments')->restrictOnDelete();
            // Existence only: the registry exposes no (id, school_id) key, and a revision is written ONLY by the
            // marks trigger, copied from a mark whose composite key already proves the same School, Student and
            // `academic_records` purpose.
            $table->foreign('processing_authorization_id', 'student_mark_revisions_authorization_fk')
                ->references('id')->on('student_processing_authorizations')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE student_marks
                ADD CONSTRAINT student_marks_status_check CHECK (status IN ('present', 'absent', 'exempt')),
                ADD CONSTRAINT student_marks_value_shape_check CHECK ((status = 'present') = (value IS NOT NULL) AND (value IS NULL OR value >= 0)),
                ADD CONSTRAINT student_marks_source_check CHECK (eligibility_source IN ('required', 'elective')),
                ADD CONSTRAINT student_marks_source_shape_check CHECK ((eligibility_source = 'elective') = (student_subject_enrollment_id IS NOT NULL)),
                ADD CONSTRAINT student_marks_purpose_check CHECK (processing_purpose = 'academic_records'),
                ADD CONSTRAINT student_marks_version_check CHECK (version >= 1);
            ALTER TABLE student_mark_revisions
                ADD CONSTRAINT student_mark_revisions_status_check CHECK (new_status IN ('present', 'absent', 'exempt') AND (previous_status IS NULL OR previous_status IN ('present', 'absent', 'exempt'))),
                ADD CONSTRAINT student_mark_revisions_shape_check CHECK ((new_status = 'present') = (new_value IS NOT NULL) AND (previous_status IS NOT NULL OR previous_value IS NULL)),
                ADD CONSTRAINT student_mark_revisions_source_check CHECK (eligibility_source IN ('required', 'elective'));

            -- The cross-row shape: the paper's year, maximum and Offering; the placement's context; the elective row's
            -- Offering; immutable identity; one version step per write. Messages never carry a value.
            CREATE FUNCTION student_marks_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_paper record;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.examination_paper_id IS DISTINCT FROM OLD.examination_paper_id
                       OR NEW.student_id IS DISTINCT FROM OLD.student_id OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id THEN
                        RAISE EXCEPTION 'student_marks: a mark''s School, paper, Student and year never change' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.version <> OLD.version + 1 THEN
                        RAISE EXCEPTION 'student_marks: every write advances the version by exactly one' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.version <> 1 THEN
                    RAISE EXCEPTION 'student_marks: a new mark starts at version 1' USING ERRCODE = 'check_violation';
                END IF;

                SELECT academic_year_id, campus_id, grade_level_id, subject_offering_id, max_marks INTO v_paper
                  FROM public.examination_papers WHERE id = NEW.examination_paper_id AND school_id = NEW.school_id;
                IF NOT FOUND OR v_paper.academic_year_id IS DISTINCT FROM NEW.academic_year_id THEN
                    RAISE EXCEPTION 'student_marks: the mark is not in its paper''s academic year' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.value IS NOT NULL AND NEW.value > v_paper.max_marks THEN
                    RAISE EXCEPTION 'student_marks: the value exceeds the paper''s maximum marks' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.student_enrollments e
                     WHERE e.id = NEW.student_enrollment_id AND e.school_id = NEW.school_id
                       AND e.campus_id = v_paper.campus_id AND e.grade_level_id = v_paper.grade_level_id
                ) THEN
                    RAISE EXCEPTION 'student_marks: the placement is not in the paper''s campus and grade' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.student_subject_enrollment_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM public.student_subject_enrollments s
                     WHERE s.id = NEW.student_subject_enrollment_id AND s.school_id = NEW.school_id
                       AND s.student_id = NEW.student_id AND s.subject_offering_id = v_paper.subject_offering_id
                       AND s.academic_year_id = NEW.academic_year_id
                ) THEN
                    RAISE EXCEPTION 'student_marks: the elective enrollment is not this Student''s enrollment in the paper''s Offering' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_marks_guard() FROM PUBLIC;
            CREATE TRIGGER student_marks_guard_trigger BEFORE INSERT OR UPDATE ON student_marks
                FOR EACH ROW EXECUTE FUNCTION student_marks_guard();

            -- Every write's value history, written here and only here.
            CREATE FUNCTION student_marks_record_revision() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                INSERT INTO public.student_mark_revisions (
                    id, school_id, student_mark_id, revision, previous_status, previous_value, new_status, new_value,
                    student_enrollment_id, eligibility_source, student_subject_enrollment_id, processing_authorization_id,
                    recorded_by_user_id, recorded_at
                ) VALUES (
                    gen_random_uuid(), NEW.school_id, NEW.id, NEW.version,
                    CASE WHEN TG_OP = 'UPDATE' THEN OLD.status END, CASE WHEN TG_OP = 'UPDATE' THEN OLD.value END,
                    NEW.status, NEW.value, NEW.student_enrollment_id, NEW.eligibility_source,
                    NEW.student_subject_enrollment_id, NEW.processing_authorization_id, NEW.recorded_by_user_id, now()
                );
                RETURN NULL;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_marks_record_revision() FROM PUBLIC;
            CREATE TRIGGER student_marks_revision_trigger AFTER INSERT OR UPDATE ON student_marks
                FOR EACH ROW EXECUTE FUNCTION student_marks_record_revision();

            CREATE FUNCTION student_mark_revisions_only_from_marks() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'student_mark_revisions: history is written only by a mark write' USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_mark_revisions_only_from_marks() FROM PUBLIC;
            CREATE TRIGGER student_mark_revisions_origin_trigger BEFORE INSERT ON student_mark_revisions
                FOR EACH ROW EXECUTE FUNCTION student_mark_revisions_only_from_marks();

            -- A marked paper's maximum and date (the P3 eligibility date) are frozen.
            CREATE FUNCTION examination_papers_freeze_when_marked() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF (NEW.max_marks IS DISTINCT FROM OLD.max_marks OR NEW.scheduled_on IS DISTINCT FROM OLD.scheduled_on)
                   AND EXISTS (SELECT 1 FROM public.student_marks m WHERE m.examination_paper_id = OLD.id AND m.school_id = OLD.school_id) THEN
                    RAISE EXCEPTION 'examination_papers: a paper with recorded marks keeps its maximum marks and date' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION examination_papers_freeze_when_marked() FROM PUBLIC;
            CREATE TRIGGER examination_papers_freeze_when_marked_trigger BEFORE UPDATE OF max_marks, scheduled_on ON examination_papers
                FOR EACH ROW EXECUTE FUNCTION examination_papers_freeze_when_marked();

            -- E21-RH.7 (ADR 0066 §15): database-recorded anchors (links tracked) and the retention delete guards.
            ALTER TABLE student_marks ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON student_marks FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('examination_paper_id', 'academic_year_id', 'student_id', 'student_enrollment_id', 'student_subject_enrollment_id', 'processing_authorization_id');
            CREATE TRIGGER trg_retention_guard_student_marks AFTER DELETE ON student_marks
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            ALTER TABLE student_mark_revisions ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON student_mark_revisions FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('student_mark_id', 'student_enrollment_id', 'student_subject_enrollment_id', 'processing_authorization_id');
            CREATE TRIGGER trg_retention_guard_student_mark_revisions AFTER DELETE ON student_mark_revisions
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('student_marks');
        TenantRls::revokeDelete('student_marks');
        TenantRls::enable('student_mark_revisions');
        TenantRls::makeAppendOnly('student_mark_revisions');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS examination_papers_freeze_when_marked_trigger ON examination_papers;
            DROP FUNCTION IF EXISTS examination_papers_freeze_when_marked();
            SQL);
        TenantRls::disable('student_mark_revisions');
        Schema::dropIfExists('student_mark_revisions');
        TenantRls::disable('student_marks');
        Schema::dropIfExists('student_marks');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS student_mark_revisions_only_from_marks();
            DROP FUNCTION IF EXISTS student_marks_record_revision();
            DROP FUNCTION IF EXISTS student_marks_guard();
            SQL);
    }
};
