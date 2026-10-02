<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification):
 * year-bound academic operations go 7 calendar years after the end of
 * their Academic Year.
 *
 * 1. The clock must not move: `academic_years.starts_on`/`ends_on` become
 *    immutable for every role. No application path edits them (the API
 *    edits name/code only; the date range is fixed at creation), so this
 *    pins the existing contract the retention clock relies on.
 *
 * 2. LMS Learning Content and Assignments carry Section audiences that are
 *    append-only (no runtime DELETE) and leave only with their parent. Each
 *    kind gets one fixed retention function (E21.2B pattern: SECURITY
 *    DEFINER, pinned `search_path`, one resource, tenant tie, EXECUTE for
 *    the runtime role only, called only through `RetentionExpiry`). The
 *    DATABASE re-proves, under a FOR UPDATE lock on the resource:
 *    - the School is the caller's tenant context;
 *    - the cutoff is at least 7 calendar years before the School-local date
 *      (at most one day ahead of UTC);
 *    - the resource's Academic Year (its SubjectOffering's, the only year
 *      its audiences can have) ended strictly before the cutoff;
 *    - for a teacher-owned resource, the D6 minimum: no TeachingAssignment
 *      of the owner over an audience Section of that Offering is open or
 *      ended on/after the cutoff;
 *    - no Document still belongs to it (the caller removes them, with their
 *      bytes, first).
 *    Then it deletes the audience rows and the resource. The owner and the
 *    audience are never removed on their own.
 *
 * Rollback drops the mechanism only; no retained row is touched.
 */
return new class extends Migration
{
    /** @var array<string, array{table: string, bridge: string, fk: string}> function => resource */
    private const LMS = [
        'retention_expire_learning_content' => ['table' => 'learning_content', 'bridge' => 'learning_content_section_audiences', 'fk' => 'learning_content_id'],
        'retention_expire_assignment' => ['table' => 'assignments', 'bridge' => 'assignment_section_audiences', 'fk' => 'assignment_id'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION academic_years_freeze_dates() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                RAISE EXCEPTION 'academic_years: the date range is fixed at creation (the retention clock of everything in the year depends on it)'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER academic_years_freeze_dates
                BEFORE UPDATE OF starts_on, ends_on ON academic_years
                FOR EACH ROW WHEN (NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.ends_on IS DISTINCT FROM OLD.ends_on)
                EXECUTE FUNCTION academic_years_freeze_dates();
            SQL);

        foreach (self::LMS as $function => ['table' => $table, 'bridge' => $bridge, 'fk' => $fk]) {
            DB::unprepared(<<<SQL
                CREATE FUNCTION {$function}(p_school_id uuid, p_id uuid, p_cutoff date, p_dry_run boolean)
                    RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
                DECLARE
                    v_owner uuid;
                    v_offering uuid;
                    v_count integer;
                BEGIN
                    PERFORM public.retention_assert_tenant(p_school_id);
                    IF p_cutoff IS NULL OR p_cutoff > ((((now() AT TIME ZONE 'UTC')::date + 1) - interval '7 years')::date) THEN
                        RAISE EXCEPTION 'retention: cutoff % is younger than the academic period (retention_floor)', p_cutoff;
                    END IF;

                    SELECT owner_employee_id, subject_offering_id INTO v_owner, v_offering
                      FROM public.{$table} WHERE school_id = p_school_id AND id = p_id FOR UPDATE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'retention: unknown LMS resource (retention_lms)';
                    END IF;

                    IF NOT EXISTS (SELECT 1 FROM public.subject_offerings o
                                     JOIN public.academic_years y ON y.id = o.academic_year_id AND y.school_id = o.school_id
                                    WHERE o.school_id = p_school_id AND o.id = v_offering AND y.ends_on < p_cutoff) THEN
                        RAISE EXCEPTION 'retention: the Academic Year of the resource ended within the period (retention_floor)';
                    END IF;

                    IF v_owner IS NOT NULL AND EXISTS (
                        SELECT 1 FROM public.teaching_assignments t
                          JOIN public.{$bridge} a ON a.school_id = t.school_id AND a.section_id = t.section_id
                         WHERE a.school_id = p_school_id AND a.{$fk} = p_id
                           AND t.employee_id = v_owner AND t.subject_offering_id = v_offering
                           AND (t.ends_on IS NULL OR t.ends_on >= p_cutoff)) THEN
                        RAISE EXCEPTION 'retention: the owner''s authority over an audience Section ended within the period (retention_lms_authority)';
                    END IF;

                    IF EXISTS (SELECT 1 FROM public.documents WHERE school_id = p_school_id AND {$fk} = p_id) THEN
                        RAISE EXCEPTION 'retention: the resource still has Documents (retention_lms_dependency)';
                    END IF;

                    SELECT count(*) + 1 INTO v_count FROM public.{$bridge} WHERE school_id = p_school_id AND {$fk} = p_id;
                    IF p_dry_run THEN
                        RETURN v_count;
                    END IF;

                    DELETE FROM public.{$bridge} WHERE school_id = p_school_id AND {$fk} = p_id;
                    DELETE FROM public.{$table} WHERE school_id = p_school_id AND id = p_id;

                    RETURN v_count;
                END;
                \$\$;
                SQL);

            DB::statement("REVOKE ALL ON FUNCTION {$function}(uuid, uuid, date, boolean) FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}(uuid, uuid, date, boolean) TO school_os_app");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LMS) as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}(uuid, uuid, date, boolean)");
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS academic_years_freeze_dates ON academic_years;
            DROP FUNCTION IF EXISTS academic_years_freeze_dates();
            SQL);
    }
};
