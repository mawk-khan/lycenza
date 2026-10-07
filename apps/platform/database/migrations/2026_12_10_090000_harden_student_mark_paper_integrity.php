<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S6 (ADR 0068 §27.11; StudentMark database defence in depth). The
 * application paths were already safe (they take the paper FOR SHARE first);
 * a RAW runtime-role writer was not:
 *
 * 1. student_marks_paper_share (BEFORE INSERT OR UPDATE on student_marks,
 *    named to fire FIRST): the mark's paper row FOR SHARE. The existing
 *    guards then read a paper no concurrent writer can change or lock under
 *    them -- student_marks_lock_guard no longer misses an uncommitted lock
 *    (a mark landed on a locked paper), and student_marks_guard no longer
 *    validates a mark against an Offering the paper is being re-pointed away
 *    from. Re-entrant for the application paths (they already hold it).
 * 2. examination_papers_freeze_when_marked, extended: a paper with any mark
 *    also keeps its Examination and Subject Offering (with its maximum and
 *    date, frozen since RES.2). The UPDATE has locked the paper row before the
 *    trigger runs, so it waits for, then sees, a concurrent first mark. Its
 *    year / campus / grade follow the Examination and Offering through the
 *    composite foreign keys; its School through the marks' composite key.
 *
 * Preflight: refuses to install over marks that already contradict their paper
 * (year, placement context, elective Offering), per School under that School's
 * context (forced RLS binds the owner too) -- reports counts, rewrites nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $contradictions = [];
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::transaction(function () use ($schoolId, &$contradictions): void {
                DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
                $count = (int) DB::selectOne(<<<'SQL'
                    SELECT count(*) AS c FROM student_marks m
                      JOIN examination_papers p ON p.id = m.examination_paper_id AND p.school_id = m.school_id
                      JOIN student_enrollments e ON e.id = m.student_enrollment_id AND e.school_id = m.school_id
                      LEFT JOIN student_subject_enrollments s ON s.id = m.student_subject_enrollment_id AND s.school_id = m.school_id
                     WHERE m.school_id = ?
                       AND (m.academic_year_id IS DISTINCT FROM p.academic_year_id
                            OR e.campus_id IS DISTINCT FROM p.campus_id OR e.grade_level_id IS DISTINCT FROM p.grade_level_id
                            OR (m.student_subject_enrollment_id IS NOT NULL AND s.subject_offering_id IS DISTINCT FROM p.subject_offering_id))
                    SQL, [$schoolId])->c;
                if ($count > 0) {
                    $contradictions[] = "school {$schoolId}: {$count}";
                }
            });
        }
        if ($contradictions !== []) {
            throw new RuntimeException('Refusing to harden StudentMark paper integrity: marks already contradict their paper ('.implode('; ', $contradictions).'). Resolve the data deliberately first; nothing was changed.');
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_marks_paper_share() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                -- Not visible here (another School, no context): RLS and the composite keys refuse the row themselves.
                PERFORM 1 FROM public.examination_papers p
                  WHERE p.id = NEW.examination_paper_id AND p.school_id = NEW.school_id
                  FOR SHARE;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_marks_paper_share() FROM PUBLIC;
            CREATE TRIGGER student_marks_a_paper_share_trigger BEFORE INSERT OR UPDATE ON student_marks
                FOR EACH ROW EXECUTE FUNCTION student_marks_paper_share();

            CREATE OR REPLACE FUNCTION examination_papers_freeze_when_marked() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.student_marks m WHERE m.examination_paper_id = OLD.id AND m.school_id = OLD.school_id) THEN
                    RETURN NEW;
                END IF;
                IF NEW.examination_id IS DISTINCT FROM OLD.examination_id OR NEW.subject_offering_id IS DISTINCT FROM OLD.subject_offering_id THEN
                    RAISE EXCEPTION 'examination_papers: a paper with recorded marks keeps its Examination and Subject Offering' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.max_marks IS DISTINCT FROM OLD.max_marks OR NEW.scheduled_on IS DISTINCT FROM OLD.scheduled_on THEN
                    RAISE EXCEPTION 'examination_papers: a paper with recorded marks keeps its maximum marks and date' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION examination_papers_freeze_when_marked() FROM PUBLIC;
            DROP TRIGGER examination_papers_freeze_when_marked_trigger ON examination_papers;
            CREATE TRIGGER examination_papers_freeze_when_marked_trigger
                BEFORE UPDATE OF max_marks, scheduled_on, examination_id, subject_offering_id ON examination_papers
                FOR EACH ROW EXECUTE FUNCTION examination_papers_freeze_when_marked();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS student_marks_a_paper_share_trigger ON student_marks;
            DROP FUNCTION IF EXISTS student_marks_paper_share();

            CREATE OR REPLACE FUNCTION examination_papers_freeze_when_marked() RETURNS trigger
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
            DROP TRIGGER examination_papers_freeze_when_marked_trigger ON examination_papers;
            CREATE TRIGGER examination_papers_freeze_when_marked_trigger BEFORE UPDATE OF max_marks, scheduled_on ON examination_papers
                FOR EACH ROW EXECUTE FUNCTION examination_papers_freeze_when_marked();
            SQL);
    }
};
