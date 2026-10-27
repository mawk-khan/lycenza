<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SIS 4.2 (placement safety). Two narrow changes to `student_enrollments`
 * integrity, nothing else:
 *
 * 1. Start-date backstop (SIS 4.1's rule, now also in the database).
 *    `student_enrollments_end_within_year` -- the existing trigger that
 *    already bounds `ends_on` by the year -- also refuses an INSERT whose
 *    `starts_on` lies outside its Academic Year (both ends inclusive, plain
 *    dates). The year is the row's own `academic_year_id`, which the ORG.7D-0
 *    composite key binds to the Section, so it is the Section's year. Only on
 *    INSERT: `starts_on` can never change afterwards (`retention_guard_end_record`).
 *    A closed year is deliberately NOT refused here: historical placements in
 *    a closed year are legitimate rows (imports, fixtures, repair), and "no
 *    NEW placement in a closed year" stays the service rule under its year
 *    FOR SHARE lock (StudentEnrollmentService::assertPlaceable()).
 *
 * 2. Pre-start cancellation (owner decision, SIS 4.2). A placement cancelled
 *    BEFORE its start date was never effective. The only honest end date is
 *    the empty interval `ends_on = starts_on - 1` -- `ends_on = starts_on`
 *    would claim one effective day and put the Student on that day's roster,
 *    because rosters read intervals, not status. The date-range CHECK
 *    therefore also admits `status = 'cancelled' AND ends_on = starts_on - 1`
 *    (the same shape `teaching_assignments_date_range_check` uses for an
 *    assignment ended before it began). The real cancellation time is the
 *    database's own `ended_recorded_at`, the lifecycle event and the audit.
 *
 * Existing rows: every placement satisfies both rules (audited before this
 * migration: no start outside its year, no end before its start).
 *
 * Rollback restores the original function and CHECK. It refuses (rather than
 * rewrite history) while any empty-interval cancellation exists: that row is
 * valid only under this migration's rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.student_enrollments_end_within_year() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                year_start date;
                year_end date;
            BEGIN
                SELECT y.starts_on, y.ends_on INTO year_start, year_end FROM public.academic_years y
                 WHERE y.id = NEW.academic_year_id AND y.school_id = NEW.school_id;
                -- SIS 4.2: a new placement starts inside its (Section's) academic year.
                IF TG_OP = 'INSERT' AND (year_start IS NULL OR NEW.starts_on < year_start OR NEW.starts_on > year_end) THEN
                    RAISE EXCEPTION 'student_enrollments_start_within_year: placement % starts % outside its academic year (% to %)', NEW.id, NEW.starts_on, year_start, year_end
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.ends_on IS NULL THEN
                    RETURN NEW;
                END IF;
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

            ALTER TABLE student_enrollments DROP CONSTRAINT student_enrollments_date_range_check;
            ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_date_range_check
                CHECK (ends_on IS NULL OR ends_on >= starts_on OR (status = 'cancelled' AND ends_on = starts_on - 1));
            SQL);
    }

    public function down(): void
    {
        if (DB::table('student_enrollments')->whereColumn('ends_on', '<', 'starts_on')->exists()) {
            throw new RuntimeException('Rollback refused: a placement was cancelled before its start (ends_on = starts_on - 1), which only this migration\'s rule admits. Its history is never rewritten.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE student_enrollments DROP CONSTRAINT student_enrollments_date_range_check;
            ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_date_range_check
                CHECK (ends_on IS NULL OR ends_on >= starts_on);

            CREATE OR REPLACE FUNCTION public.student_enrollments_end_within_year() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
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
            SQL);
    }
};
