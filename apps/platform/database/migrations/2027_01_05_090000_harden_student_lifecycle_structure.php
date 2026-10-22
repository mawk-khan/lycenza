<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8G (ADR 0076 §65, closes G-6): the Student lifecycle invariants that
 * PostgreSQL can enforce without knowing which human acted.
 *
 * - **Status domains.** `students.status` is `active` or `inactive`;
 *   `student_enrollments.status` is one of the five placement states. An
 *   unknown value is refused for every role.
 * - **Born active.** A Student and a placement are INSERTed `active` only.
 *   Every product writer already does (`StudentService::create`,
 *   `StudentEnrollmentService`); an ended or inactive state is reached only
 *   by a transition, which is where its evidence is checked. (Option B of
 *   the brief: no legitimate path inserts a terminal row.)
 * - **Student status evidence.** `students_lifecycle_evidence`: a status
 *   change needs its `student_lifecycle_events` row in the same transaction
 *   (School, Student, direction-matching action, no placement,
 *   `occurred_at = now()`), exactly like the existing
 *   `student_enrollments_lifecycle_evidence`.
 * - **Terminal shape.** `student_enrollments_end_shape_check`: a placement
 *   is `active` exactly when it has no `ends_on` (the existing
 *   `ends_on >= starts_on` CHECK stays).
 * - **Year bound.** `student_enrollments_end_within_year`: `ends_on` never
 *   passes the placement's academic year end, and a `completed` placement
 *   ends exactly on it -- leaving earlier is a withdrawal or cancellation,
 *   with their evidence (year dates are frozen at creation,
 *   `academic_years_freeze_dates`, so the bound is stable). An ended row's
 *   status and end are already final for the runtime role
 *   (`retention_guard_end_record`).
 *
 * What the database still cannot know is WHO acted: the event's
 * `acted_by_user_id` is the caller's claim (the documented actor-context
 * boundary, §59.6). "Not in the future" is a clock rule, enforced by the
 * service (School-local date), not here.
 *
 * Rollback drops only these rules; every row valid under them stays valid
 * under the previous schema, so nothing is stranded or lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE students ADD CONSTRAINT students_status_check CHECK (status IN ('active', 'inactive'));
            ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_status_check
                CHECK (status IN ('active', 'completed', 'withdrawn', 'transferred', 'cancelled'));
            ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_end_shape_check
                CHECK ((status = 'active') = (ends_on IS NULL));

            CREATE FUNCTION public.students_lifecycle_born_active() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.status IS DISTINCT FROM 'active' THEN
                    RAISE EXCEPTION '%_born_active: a new row starts active; % is reached only by its lifecycle transition', TG_TABLE_NAME, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER students_born_active BEFORE INSERT ON students
                FOR EACH ROW EXECUTE FUNCTION public.students_lifecycle_born_active();
            CREATE TRIGGER student_enrollments_born_active BEFORE INSERT ON student_enrollments
                FOR EACH ROW EXECUTE FUNCTION public.students_lifecycle_born_active();

            CREATE FUNCTION public.students_require_lifecycle_evidence() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.student_lifecycle_events e
                     WHERE e.school_id = NEW.school_id AND e.student_id = NEW.id AND e.student_enrollment_id IS NULL
                       AND e.action = CASE NEW.status WHEN 'inactive' THEN 'student_deactivated' ELSE 'student_reactivated' END
                       AND e.occurred_at = now()
                ) THEN
                    RAISE EXCEPTION 'students_lifecycle_evidence: student % becomes % without its lifecycle event', NEW.id, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER students_lifecycle_evidence BEFORE UPDATE OF status ON students
                FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status)
                EXECUTE FUNCTION public.students_require_lifecycle_evidence();

            CREATE FUNCTION public.student_enrollments_end_within_year() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                year_end date;
            BEGIN
                IF NEW.ends_on IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT y.ends_on INTO year_end FROM public.academic_years y
                 WHERE y.id = NEW.academic_year_id AND y.school_id = NEW.school_id;
                IF year_end IS NULL OR NEW.ends_on > year_end THEN
                    RAISE EXCEPTION 'student_enrollments_end_within_year: placement % ends % after its academic year (%)', NEW.id, NEW.ends_on, year_end
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'completed' AND NEW.ends_on <> year_end THEN
                    RAISE EXCEPTION 'student_enrollments_end_within_year: placement % completes on %, not its academic year end (%)', NEW.id, NEW.ends_on, year_end
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER student_enrollments_end_within_year BEFORE INSERT OR UPDATE OF ends_on ON student_enrollments
                FOR EACH ROW EXECUTE FUNCTION public.student_enrollments_end_within_year();

            REVOKE ALL ON FUNCTION public.students_lifecycle_born_active() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.students_require_lifecycle_evidence() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.student_enrollments_end_within_year() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS student_enrollments_end_within_year ON student_enrollments;
            DROP FUNCTION IF EXISTS public.student_enrollments_end_within_year();
            DROP TRIGGER IF EXISTS students_lifecycle_evidence ON students;
            DROP FUNCTION IF EXISTS public.students_require_lifecycle_evidence();
            DROP TRIGGER IF EXISTS student_enrollments_born_active ON student_enrollments;
            DROP TRIGGER IF EXISTS students_born_active ON students;
            DROP FUNCTION IF EXISTS public.students_lifecycle_born_active();
            ALTER TABLE student_enrollments DROP CONSTRAINT IF EXISTS student_enrollments_end_shape_check;
            ALTER TABLE student_enrollments DROP CONSTRAINT IF EXISTS student_enrollments_status_check;
            ALTER TABLE students DROP CONSTRAINT IF EXISTS students_status_check;
            SQL);
    }
};
