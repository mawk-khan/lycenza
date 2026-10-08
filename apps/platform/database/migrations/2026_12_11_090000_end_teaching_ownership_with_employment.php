<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S7 (ADR 0063 §47; ADR 0068 §27.11): ending an employment ends the teaching
 * ownership it granted, required and elective alike, in the same transaction
 * (TeachingAssignmentService / ElectiveTeachingAssignmentService
 * ::endForEmployment()). Two shapes the history guards refused until now, and
 * ONLY with end_reason `employment_ended`:
 *
 * 1. a void row -- an assignment that had not started by the employment's last
 *    day ends the day before it began (ends_on = starts_on - 1), so it never
 *    covers a date; the row stays as history (never deleted, starts_on never
 *    rewritten). The date-range CHECK admits exactly that one shape.
 * 2. an already-ended row whose end is still after the employment's last day
 *    (an end scheduled ahead) is brought back to it -- shortening only, the
 *    identity unchanged. Any other change to an ended row stays refused.
 *
 * (`IS NOT DISTINCT FROM`, never `=`: a NULL reason must not make the CHECK
 * NULL, which PostgreSQL would accept.)
 *
 * Every other rule of both guards is unchanged. down() refuses while a void
 * row exists (the original CHECK cannot hold it), per School under that
 * School's context (forced RLS binds the owner too) -- it rewrites nothing.
 */
return new class extends Migration
{
    private const array TABLES = ['teaching_assignments', 'elective_teaching_assignments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_date_range_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_date_range_check "
                ."CHECK (ends_on IS NULL OR starts_on <= ends_on OR (end_reason IS NOT DISTINCT FROM 'employment_ended' AND ends_on = starts_on - 1))");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION teaching_assignments_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.ended_at IS NOT NULL THEN
                        RAISE EXCEPTION 'teaching_assignments: an assignment cannot be created already ended';
                    END IF;
                    RETURN NEW;
                END IF;

                -- S7: an employment end may bring an ended row's end earlier -- that, and nothing else.
                IF OLD.ended_at IS NOT NULL
                   AND (NEW.end_reason IS DISTINCT FROM 'employment_ended' OR NEW.ends_on IS NULL OR NEW.ends_on >= OLD.ends_on) THEN
                    RAISE EXCEPTION 'teaching_assignments: an ended assignment is immutable';
                END IF;
                IF NEW.ended_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.section_id IS DISTINCT FROM OLD.section_id
                    OR NEW.subject_offering_id IS DISTINCT FROM OLD.subject_offering_id
                    OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'teaching_assignments: the only permitted change is ending the assignment';
                END IF;
                IF OLD.ends_on IS NOT NULL AND NEW.ends_on > OLD.ends_on THEN
                    RAISE EXCEPTION 'teaching_assignments: ending may shorten an assignment, never extend it';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION elective_teaching_assignments_history_guard() RETURNS trigger
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

                -- S7: an employment end may bring an ended row's end earlier -- that, and nothing else.
                IF OLD.ended_at IS NOT NULL
                   AND (NEW.end_reason IS DISTINCT FROM 'employment_ended' OR NEW.ends_on IS NULL OR NEW.ends_on >= OLD.ends_on) THEN
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
            SQL);
    }

    public function down(): void
    {
        $voided = [];
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            DB::transaction(function () use ($schoolId, &$voided): void {
                DB::select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
                foreach (self::TABLES as $table) {
                    $count = DB::table($table)->where('school_id', $schoolId)->whereColumn('ends_on', '<', 'starts_on')->count();
                    if ($count > 0) {
                        $voided[] = "school {$schoolId} {$table}: {$count}";
                    }
                }
            });
        }
        if ($voided !== []) {
            throw new RuntimeException('Refusing to roll back S7: voided teaching assignments exist ('.implode('; ', $voided).') and the original date-range CHECK cannot hold them. Nothing was changed.');
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_date_range_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_date_range_check CHECK (ends_on IS NULL OR starts_on <= ends_on)");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION teaching_assignments_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.ended_at IS NOT NULL THEN
                        RAISE EXCEPTION 'teaching_assignments: an assignment cannot be created already ended';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.ended_at IS NOT NULL THEN
                    RAISE EXCEPTION 'teaching_assignments: an ended assignment is immutable';
                END IF;
                IF NEW.ended_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.section_id IS DISTINCT FROM OLD.section_id
                    OR NEW.subject_offering_id IS DISTINCT FROM OLD.subject_offering_id
                    OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'teaching_assignments: the only permitted change is ending the assignment';
                END IF;
                IF OLD.ends_on IS NOT NULL AND NEW.ends_on > OLD.ends_on THEN
                    RAISE EXCEPTION 'teaching_assignments: ending may shorten an assignment, never extend it';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION elective_teaching_assignments_history_guard() RETURNS trigger
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
            SQL);
    }
};
