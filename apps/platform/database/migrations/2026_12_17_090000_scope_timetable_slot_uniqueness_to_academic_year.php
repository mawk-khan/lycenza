<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LC-2 (Local Product Completion M2; owner decision 2026-10-17): timetable
 * collisions are scoped to the same School AND the same academic year.
 *
 * `timetable_entries_teacher_slot_unique` and `..._room_slot_unique` were
 * keyed (school, resource, day, period) across every year, so the next
 * year's timetable could not reuse a teacher's or room's slot while this
 * year's timetable stayed active -- a School could not prepare next year in
 * advance. Each is rebuilt with `academic_year_id` (already on every row,
 * derived from the SubjectOffering by TimetableScheduleService, and pinned
 * by the composite FKs). Same names, so TimetableScheduleService::guarded()
 * translates a violation exactly as before; same-year double-booking stays
 * impossible at the database. `timetable_entries_section_slot_unique` is
 * unchanged: a Section belongs to one academic year (rule 66), so it is
 * already year-scoped.
 *
 * Existing rows satisfy the new indexes by construction (they satisfied a
 * strictly stronger rule). No row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX timetable_entries_teacher_slot_unique');
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_teacher_slot_unique ON timetable_entries '.
            '(school_id, academic_year_id, teacher_id, day_of_week, period_id) WHERE status = \'active\''
        );

        DB::statement('DROP INDEX timetable_entries_room_slot_unique');
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_room_slot_unique ON timetable_entries '.
            '(school_id, academic_year_id, room_id, day_of_week, period_id) WHERE status = \'active\' AND room_id IS NOT NULL'
        );
    }

    /**
     * Restores the cross-year indexes only while no valid cross-year reuse
     * exists. Once a School has prepared next year's timetable with a
     * teacher or room in the same slot as this year, the old rule cannot be
     * re-imposed without deactivating or deleting valid timetable rows --
     * this migration never does that: it refuses, with a count, and changes
     * nothing (rule 10: deliberately irreversible in that state).
     */
    public function down(): void
    {
        $teacherReuse = (int) DB::scalar(
            "SELECT count(*) FROM (SELECT 1 FROM timetable_entries WHERE status = 'active' "
            .'GROUP BY school_id, teacher_id, day_of_week, period_id HAVING count(*) > 1) t'
        );
        $roomReuse = (int) DB::scalar(
            "SELECT count(*) FROM (SELECT 1 FROM timetable_entries WHERE status = 'active' AND room_id IS NOT NULL "
            .'GROUP BY school_id, room_id, day_of_week, period_id HAVING count(*) > 1) t'
        );

        if ($teacherReuse > 0 || $roomReuse > 0) {
            throw new RuntimeException(
                "Cannot restore cross-year timetable slot uniqueness: {$teacherReuse} teacher and {$roomReuse} room "
                .'slot(s) are validly reused across academic years. Deactivate those entries first; this migration never removes timetable data.'
            );
        }

        DB::statement('DROP INDEX timetable_entries_teacher_slot_unique');
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_teacher_slot_unique ON timetable_entries '.
            '(school_id, teacher_id, day_of_week, period_id) WHERE status = \'active\''
        );

        DB::statement('DROP INDEX timetable_entries_room_slot_unique');
        DB::statement(
            'CREATE UNIQUE INDEX timetable_entries_room_slot_unique ON timetable_entries '.
            '(school_id, room_id, day_of_week, period_id) WHERE status = \'active\' AND room_id IS NOT NULL'
        );
    }
};
