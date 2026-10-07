<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TCH-E (ADR 0063 section 45): the authoritative, dated teaching-ownership
 * fact for ELECTIVE SubjectOfferings -- "this Employee teaches this elective
 * Offering from starts_on to ends_on". The required-subject fact
 * (`teaching_assignments`, Section x required Offering) is unchanged.
 *
 * Offering-wide, never Section-scoped: an elective's cohort is the Students
 * enrolled in it (`student_subject_enrollments`), across Sections; there is no
 * elective Section, teaching group or timetable slot (ADR 0063 D-05,
 * TIMETABLE.md). One row per Employee x Offering period -- never per Student.
 * It authorizes nothing by itself: a consumer composes it with a verified
 * ActingEmployee, an owned-scope capability and its own gates.
 *
 * The same shape and rules as `teaching_assignments`:
 * - forced RLS; the Employee by `(id, school_id)` and the Offering by its
 *   5-column context key, so nothing of another School (or a different year,
 *   campus or grade than the stored pins) can be referenced; RESTRICT;
 * - School-local inclusive dates, `ends_on` NULL = open-ended;
 * - ended, never deleted (no runtime DELETE); the identity is frozen; one end,
 *   which may only shorten; an ended row is immutable;
 * - overlap per (School, Employee, Offering) is refused by
 *   ElectiveTeachingAssignmentService under an advisory lock -- co-teachers
 *   (different Employees) are allowed, as for required subjects;
 * - and, additionally, the database refuses a row for a REQUIRED Offering
 *   at insert (`elective_teaching_assignments_offering_guard`).
 * - E21-D6 retention exactly like `teaching_assignments` (category
 *   `authority`): anchored, delete-guarded, expired 7 years after `ends_on`
 *   by `retention_expire_elective_teaching_assignments` (retention identity
 *   only, holds respected).
 */
return new class extends Migration
{
    private const END_REASONS = "'completed', 'reassigned', 'employment_ended'";

    public function up(): void
    {
        Schema::create('elective_teaching_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('subject_offering_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'employee_id', 'subject_offering_id', 'starts_on'], 'elective_teaching_assignments_key_idx');
            $table->index(['school_id', 'subject_offering_id'], 'elective_teaching_assignments_offering_idx');
            $table->index(['academic_year_id']);
            $table->index(['employee_id']);

            $table->foreign(['employee_id', 'school_id'], 'elective_teaching_assignments_employee_fk')
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();

            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'elective_teaching_assignments_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE elective_teaching_assignments ADD CONSTRAINT elective_teaching_assignments_date_range_check '
            .'CHECK (ends_on IS NULL OR starts_on <= ends_on)');

        DB::statement('ALTER TABLE elective_teaching_assignments ADD CONSTRAINT elective_teaching_assignments_end_shape_check '
            .'CHECK ((ended_at IS NULL) = (ended_by_user_id IS NULL) AND (ended_at IS NULL) = (end_reason IS NULL) '
            .'AND (ended_at IS NULL OR ends_on IS NOT NULL) '
            .'AND (end_reason IS NULL OR end_reason IN ('.self::END_REASONS.')))');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION elective_teaching_assignments_history_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.ended_at IS NOT NULL THEN
                        RAISE EXCEPTION 'elective_teaching_assignments: an assignment cannot be created already ended';
                    END IF;
                    -- Elective Offerings only: a required Offering's ownership is a Section-scoped teaching_assignments row.
                    IF NOT EXISTS (SELECT 1 FROM public.subject_offerings o
                                    WHERE o.id = NEW.subject_offering_id AND o.school_id = NEW.school_id AND o.is_required = false) THEN
                        RAISE EXCEPTION 'elective_teaching_assignments: the Subject Offering is not an elective' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.ended_at IS NOT NULL THEN
                    RAISE EXCEPTION 'elective_teaching_assignments: an ended assignment is immutable';
                END IF;
                IF NEW.ended_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.subject_offering_id IS DISTINCT FROM OLD.subject_offering_id
                    OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'elective_teaching_assignments: the only permitted change is ending the assignment';
                END IF;
                IF OLD.ends_on IS NOT NULL AND NEW.ends_on > OLD.ends_on THEN
                    RAISE EXCEPTION 'elective_teaching_assignments: ending may shorten an assignment, never extend it';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION elective_teaching_assignments_history_guard() FROM PUBLIC;

            CREATE TRIGGER trg_elective_teaching_assignments_history
                BEFORE INSERT OR UPDATE ON elective_teaching_assignments
                FOR EACH ROW EXECUTE FUNCTION elective_teaching_assignments_history_guard();

            -- E21-RH.7 (ADR 0066 §15): the database-recorded anchor and the retention delete guard.
            ALTER TABLE elective_teaching_assignments ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON elective_teaching_assignments FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('ends_on', 'ended_at', 'academic_year_id', 'campus_id', 'employee_id', 'grade_level_id', 'subject_offering_id');
            CREATE TRIGGER trg_retention_guard_elective_teaching_assignments AFTER DELETE ON elective_teaching_assignments
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');

            -- E21-D6 (category `authority`), the retention_expire_teaching_assignments shape exactly: ended authority,
            -- 7 years after its last effective day; open and future rows never; the retention identity only; nothing
            -- destructive under a hold; only rows the database recorded before the cutoff.
            CREATE FUNCTION retention_expire_elective_teaching_assignments(p_school_id uuid, p_cutoff date, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                PERFORM set_config('app.retention_anchor_cutoff', p_cutoff::timestamp::text, true);
                IF NOT p_dry_run THEN
                    PERFORM public.retention_assert_not_held(p_school_id);
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                IF p_cutoff IS NULL OR p_cutoff > (((now() AT TIME ZONE 'UTC') - interval '7 years')::date + 1) THEN
                    RAISE EXCEPTION 'retention: cutoff % is younger than the adopted period (retention_floor)', p_cutoff;
                END IF;
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.elective_teaching_assignments
                     WHERE school_id = p_school_id AND ends_on IS NOT NULL AND ends_on < p_cutoff AND retention_recorded_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.elective_teaching_assignments t
                 WHERE t.id IN (SELECT id FROM public.elective_teaching_assignments
                                 WHERE school_id = p_school_id AND ends_on IS NOT NULL AND ends_on < p_cutoff AND retention_recorded_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND t.school_id = p_school_id AND t.ends_on IS NOT NULL AND t.ends_on < p_cutoff AND t.retention_recorded_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_expire_elective_teaching_assignments(uuid, date, integer, boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_expire_elective_teaching_assignments(uuid, date, integer, boolean) TO school_os_retention;
            SQL);

        TenantRls::enable('elective_teaching_assignments');
        TenantRls::revokeDelete('elective_teaching_assignments');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS retention_expire_elective_teaching_assignments(uuid, date, integer, boolean);
            DROP TRIGGER IF EXISTS trg_retention_guard_elective_teaching_assignments ON elective_teaching_assignments;
            DROP TRIGGER IF EXISTS zzz_retention_anchor ON elective_teaching_assignments;
            DROP TRIGGER IF EXISTS trg_elective_teaching_assignments_history ON elective_teaching_assignments;
            SQL);

        TenantRls::disable('elective_teaching_assignments');
        Schema::dropIfExists('elective_teaching_assignments');
        DB::statement('DROP FUNCTION IF EXISTS elective_teaching_assignments_history_guard()');
    }
};
