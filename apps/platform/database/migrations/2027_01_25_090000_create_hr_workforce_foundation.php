<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.1A (docs/hr/HR-0A-ARCHITECTURE-RECONCILIATION.md, HR.1A brief): the
 * effective-dated workforce foundation. Every range below is INCLUSIVE
 * (`starts_on`..`ends_on`, NULL end = open): 2026-01-01..2026-06-30 and
 * 2026-07-01..open are adjacent and valid; 2026-01-01..2026-06-30 and
 * 2026-06-30..open overlap on 2026-06-30 and are refused.
 *
 * - `employment_records.staff_type`: the closed staff classification
 *   (teaching, non_teaching, outsourced). NULL = not yet classified (legacy
 *   rows the deterministic map below cannot place); every service-created
 *   employment states one. `employment_type` becomes the closed catalogue the
 *   OpenAPI contract already declared (S-12).
 * - `employment_terms`: the authoritative, append-only history of an
 *   employment's terms (type, staff type, category). The first term starts
 *   on the employment's own start (created by trigger on every employment
 *   INSERT, whatever the path); later terms only append, strictly after the
 *   latest. `employment_records.employment_type/employee_category_id/
 *   staff_type` are a DATABASE-MAINTAINED projection of the latest term:
 *   a direct UPDATE of them is refused (X-3: no destructive overwrite).
 * - `employee_placement_changes`: the named, reasoned record of each
 *   placement change (initial placement, transfer, promotion, designation
 *   or department change, primary correction), append-only, bound to its
 *   own employment's assignments.
 * - Database backstops (S-10): no two overlapping employments of one
 *   Employee; no two overlapping PRIMARY assignments of one employment;
 *   no Department hierarchy cycle (S-9); `employee_number` and `school_id`
 *   of an Employee are immutable (S-11). The repository uses no
 *   EXCLUDE/btree_gist: each check is a trigger that first takes a
 *   transaction-scoped advisory lock on the owning key, so two concurrent
 *   writers serialize and the second reads the first's committed row
 *   (READ COMMITTED gives each trigger statement a fresh snapshot).
 *   A write that only narrows a range (an end) can never create an overlap
 *   and takes no lock.
 *
 * Pre-flight (fail safe, never guessed): the migration refuses to run while
 * an `employment_type` is outside the catalogue, two employments of one
 * Employee overlap, or two primary assignments of one employment overlap --
 * each reported with a count, nothing changed.
 *
 * Rollback: drops the new tables, triggers, checks and `staff_type`. The
 * projection columns keep the latest term's values, so no data the earlier
 * schema could hold is lost.
 */
return new class extends Migration
{
    private const EMPLOYMENT_TYPES = "'permanent', 'probationary', 'fixed_term', 'part_time', 'temporary', 'contract', 'consultant'";

    private const STAFF_TYPES = "'teaching', 'non_teaching', 'outsourced'";

    private const TERMS_REASONS = "'initial', 'backfill', 'reclassification', 'contract_change', 'correction'";

    private const CHANGE_KINDS = "'initial_placement', 'transfer', 'promotion', 'designation_change', 'department_change', 'primary_correction'";

    private const CHANGE_REASONS = "'initial_placement', 'staffing_requirement', 'employee_request', 'career_progression', 'restructuring', 'administrative_correction'";

    /**
     * The deterministic legacy staff-type map: a School category whose CODE is
     * exactly one of these. Any other code stays NULL (unclassified) -- a
     * Department, a Position or a role is never consulted.
     */
    private const STAFF_TYPE_BY_CATEGORY_CODE = [
        'TEACH' => 'teaching', 'TEACHING' => 'teaching',
        'NONTEACH' => 'non_teaching', 'NON_TEACH' => 'non_teaching', 'NONTEACHING' => 'non_teaching', 'NON_TEACHING' => 'non_teaching', 'NON-TEACHING' => 'non_teaching',
        'OUTSOURCE' => 'outsourced', 'OUTSOURCED' => 'outsourced',
    ];

    public function up(): void
    {
        $this->preflight();

        // ---------------------------------------------------------------
        // employment_records: closed type, staff type, overlap backstop
        // ---------------------------------------------------------------
        Schema::table('employment_records', function (Blueprint $table) {
            $table->string('staff_type', 20)->nullable()->after('employment_type');
        });
        DB::statement('ALTER TABLE employment_records ADD CONSTRAINT employment_records_employment_type_check CHECK (employment_type IN ('.self::EMPLOYMENT_TYPES.'))');
        DB::statement('ALTER TABLE employment_records ADD CONSTRAINT employment_records_staff_type_check CHECK (staff_type IS NULL OR staff_type IN ('.self::STAFF_TYPES.'))');

        foreach (self::STAFF_TYPE_BY_CATEGORY_CODE as $code => $staffType) {
            DB::update(
                'UPDATE employment_records er SET staff_type = ? FROM employee_categories c
                  WHERE c.id = er.employee_category_id AND c.school_id = er.school_id AND c.code = ? AND er.staff_type IS NULL',
                [$staffType, $code],
            );
        }

        // ---------------------------------------------------------------
        // employment_terms
        // ---------------------------------------------------------------
        Schema::create('employment_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->date('effective_from');
            $table->string('employment_type', 20);
            $table->string('staff_type', 20)->nullable();
            $table->uuid('employee_category_id')->nullable();
            $table->string('reason_code', 30);
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['employment_record_id', 'effective_from'], 'employment_terms_one_per_day');
            $table->index('school_id');
            $table->index('employee_category_id');
            $table->index('recorded_by_user_id');

            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
            $table->foreign(['employee_category_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_categories')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE employment_terms ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE employment_terms ADD CONSTRAINT employment_terms_employment_type_check CHECK (employment_type IN ('.self::EMPLOYMENT_TYPES.'))');
        DB::statement('ALTER TABLE employment_terms ADD CONSTRAINT employment_terms_staff_type_check CHECK (staff_type IS NULL OR staff_type IN ('.self::STAFF_TYPES.'))');
        DB::statement('ALTER TABLE employment_terms ADD CONSTRAINT employment_terms_reason_check CHECK (reason_code IN ('.self::TERMS_REASONS.'))');
        // A recorded change names who made it; only the trigger-created first term
        // (actor in hr.employment.created) and the HR.1A backfill carry none.
        DB::statement("ALTER TABLE employment_terms ADD CONSTRAINT employment_terms_actor_check CHECK (reason_code IN ('initial', 'backfill') OR recorded_by_user_id IS NOT NULL)");
        // A change after HR.1A always classifies the staff type.
        DB::statement("ALTER TABLE employment_terms ADD CONSTRAINT employment_terms_classified_check CHECK (reason_code IN ('initial', 'backfill') OR staff_type IS NOT NULL)");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_employment_terms_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_starts date;
                v_ends date;
                v_latest date;
            BEGIN
                SELECT r.starts_on, r.ends_on INTO v_starts, v_ends FROM public.employment_records r
                 WHERE r.id = NEW.employment_record_id AND r.school_id = NEW.school_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'employment terms belong to an employment of the same School (hr_employment_terms_append_guard)' USING ERRCODE = 'foreign_key_violation';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('hr.employment_terms:' || NEW.employment_record_id::text, 0));
                SELECT max(t.effective_from) INTO v_latest FROM public.employment_terms t WHERE t.employment_record_id = NEW.employment_record_id;
                IF v_latest IS NULL THEN
                    IF NEW.effective_from <> v_starts OR NEW.reason_code NOT IN ('initial', 'backfill') THEN
                        RAISE EXCEPTION 'the first terms of an employment start on its start date (hr_employment_terms_append_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.reason_code IN ('initial', 'backfill') OR NEW.effective_from <= v_latest
                      OR NEW.effective_from > COALESCE(v_ends, 'infinity'::date) THEN
                    RAISE EXCEPTION 'employment terms only append, after the latest terms and within the employment (hr_employment_terms_append_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employment_terms_append_guard BEFORE INSERT ON employment_terms
                FOR EACH ROW EXECUTE FUNCTION hr_employment_terms_append_guard();

            CREATE FUNCTION hr_history_row_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only history (hr_history_row_immutable)', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER trg_employment_terms_immutable BEFORE UPDATE ON employment_terms
                FOR EACH ROW EXECUTE FUNCTION hr_history_row_immutable();

            -- The projection on employment_records follows each appended change.
            CREATE FUNCTION hr_employment_terms_project() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.reason_code NOT IN ('initial', 'backfill') THEN
                    UPDATE public.employment_records
                       SET employment_type = NEW.employment_type, staff_type = NEW.staff_type, employee_category_id = NEW.employee_category_id
                     WHERE id = NEW.employment_record_id AND school_id = NEW.school_id;
                END IF;
                RETURN NULL;
            END $$;
            CREATE TRIGGER trg_employment_terms_project AFTER INSERT ON employment_terms
                FOR EACH ROW EXECUTE FUNCTION hr_employment_terms_project();
            SQL);

        // Backfill: one term per existing employment, from its current values
        // (the only evidence that exists; no earlier history is fabricated).
        DB::insert(
            "INSERT INTO employment_terms (id, school_id, employment_record_id, effective_from, employment_type, staff_type, employee_category_id, reason_code, recorded_by_user_id, created_at)
             SELECT gen_random_uuid(), er.school_id, er.id, er.starts_on, er.employment_type, er.staff_type, er.employee_category_id, 'backfill', NULL, now()
               FROM employment_records er ORDER BY er.school_id, er.id",
        );

        TenantRls::enable('employment_terms');
        DB::statement('REVOKE UPDATE, DELETE ON employment_terms FROM school_os_app');

        DB::unprepared(<<<'SQL'
            -- Every employment gets its first terms, whatever path inserts it.
            CREATE FUNCTION hr_employment_records_initial_terms() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO public.employment_terms (id, school_id, employment_record_id, effective_from, employment_type, staff_type, employee_category_id, reason_code, recorded_by_user_id, created_at)
                VALUES (gen_random_uuid(), NEW.school_id, NEW.id, NEW.starts_on, NEW.employment_type, NEW.staff_type, NEW.employee_category_id, 'initial', NULL, now());
                RETURN NULL;
            END $$;
            CREATE TRIGGER trg_employment_records_initial_terms AFTER INSERT ON employment_records
                FOR EACH ROW EXECUTE FUNCTION hr_employment_records_initial_terms();

            -- The terms projection changes only through employment_terms (its
            -- AFTER INSERT trigger runs the UPDATE at trigger depth 2).
            CREATE FUNCTION hr_employment_records_terms_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.employment_type IS DISTINCT FROM OLD.employment_type OR NEW.staff_type IS DISTINCT FROM OLD.staff_type
                    OR NEW.employee_category_id IS DISTINCT FROM OLD.employee_category_id) AND pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'employment terms change only by appending employment_terms (hr_employment_records_terms_projection_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employment_records_terms_projection_guard BEFORE UPDATE ON employment_records
                FOR EACH ROW EXECUTE FUNCTION hr_employment_records_terms_projection_guard();

            -- S-10: at most one employment of an Employee on any day (inclusive ranges).
            CREATE FUNCTION hr_employment_records_no_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.employee_id = OLD.employee_id AND NEW.starts_on >= OLD.starts_on
                   AND COALESCE(NEW.ends_on, 'infinity'::date) <= COALESCE(OLD.ends_on, 'infinity'::date) THEN
                    RETURN NEW; -- only narrows: cannot create an overlap
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('hr.employment:' || NEW.employee_id::text, 0));
                IF EXISTS (SELECT 1 FROM public.employment_records r
                            WHERE r.employee_id = NEW.employee_id AND r.id <> NEW.id
                              AND r.starts_on <= COALESCE(NEW.ends_on, 'infinity'::date)
                              AND NEW.starts_on <= COALESCE(r.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'an Employee has at most one employment on any day (hr_employment_records_no_overlap)' USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employment_records_no_overlap BEFORE INSERT OR UPDATE ON employment_records
                FOR EACH ROW EXECUTE FUNCTION hr_employment_records_no_overlap();

            -- At most one PRIMARY assignment of an employment on any day (inclusive ranges).
            CREATE FUNCTION hr_employee_assignments_primary_no_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT NEW.is_primary THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.is_primary AND NEW.employment_record_id = OLD.employment_record_id AND NEW.starts_on >= OLD.starts_on
                   AND COALESCE(NEW.ends_on, 'infinity'::date) <= COALESCE(OLD.ends_on, 'infinity'::date) THEN
                    RETURN NEW; -- only narrows: cannot create an overlap
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('hr.primary_assignment:' || NEW.employment_record_id::text, 0));
                IF EXISTS (SELECT 1 FROM public.employee_assignments a
                            WHERE a.employment_record_id = NEW.employment_record_id AND a.id <> NEW.id AND a.is_primary
                              AND a.starts_on <= COALESCE(NEW.ends_on, 'infinity'::date)
                              AND NEW.starts_on <= COALESCE(a.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'an employment has at most one primary assignment on any day (hr_employee_assignments_primary_no_overlap)' USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employee_assignments_primary_no_overlap BEFORE INSERT OR UPDATE ON employee_assignments
                FOR EACH ROW EXECUTE FUNCTION hr_employee_assignments_primary_no_overlap();

            -- S-11: an Employee's number and School never change.
            CREATE FUNCTION hr_employees_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.employee_number IS DISTINCT FROM OLD.employee_number OR NEW.school_id IS DISTINCT FROM OLD.school_id THEN
                    RAISE EXCEPTION 'an Employee''s number and School are immutable (hr_employees_identity_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employees_identity_guard BEFORE UPDATE ON employees
                FOR EACH ROW EXECUTE FUNCTION hr_employees_identity_guard();

            -- S-9: no Department hierarchy cycle, serialized per School.
            CREATE FUNCTION hr_departments_no_cycle() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_current uuid := NEW.parent_department_id;
                v_steps integer := 0;
            BEGIN
                IF v_current IS NULL OR (TG_OP = 'UPDATE' AND NEW.parent_department_id IS NOT DISTINCT FROM OLD.parent_department_id) THEN
                    RETURN NEW;
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('hr.departments:' || NEW.school_id::text, 0));
                WHILE v_current IS NOT NULL LOOP
                    IF v_current = NEW.id OR v_steps > 10000 THEN
                        RAISE EXCEPTION 'a Department cannot be its own ancestor (hr_departments_no_cycle)' USING ERRCODE = 'check_violation';
                    END IF;
                    v_steps := v_steps + 1;
                    SELECT d.parent_department_id INTO v_current FROM public.hr_departments d WHERE d.id = v_current AND d.school_id = NEW.school_id;
                END LOOP;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_hr_departments_no_cycle BEFORE INSERT OR UPDATE ON hr_departments
                FOR EACH ROW EXECUTE FUNCTION hr_departments_no_cycle();
            SQL);

        // ---------------------------------------------------------------
        // employee_placement_changes
        // ---------------------------------------------------------------
        Schema::create('employee_placement_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->string('change_kind', 30);
            $table->uuid('from_assignment_id')->nullable();
            $table->uuid('to_assignment_id');
            $table->date('effective_on');
            $table->string('reason_code', 40);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'employment_record_id']);
            $table->index('from_assignment_id');
            $table->index('to_assignment_id');
            $table->index('recorded_by_user_id');

            // History goes with its owner: the employment and its assignments
            // (runtime DELETE of either is revoked; only retention removes them).
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
            $table->foreign(['from_assignment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_assignments')
                ->cascadeOnDelete();
            $table->foreign(['to_assignment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_assignments')
                ->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE employee_placement_changes ADD CONSTRAINT employee_placement_changes_kind_check CHECK (change_kind IN ('.self::CHANGE_KINDS.'))');
        DB::statement('ALTER TABLE employee_placement_changes ADD CONSTRAINT employee_placement_changes_reason_check CHECK (reason_code IN ('.self::CHANGE_REASONS.'))');
        DB::statement("ALTER TABLE employee_placement_changes ADD CONSTRAINT employee_placement_changes_shape_check CHECK (
            ((change_kind = 'initial_placement') = (from_assignment_id IS NULL))
            AND ((change_kind = 'initial_placement') = (reason_code = 'initial_placement'))
            AND (from_assignment_id IS NULL OR from_assignment_id <> to_assignment_id))");

        DB::unprepared(<<<'SQL'
            -- Both assignments belong to the change's own employment; the new one
            -- starts on the effective date; a dated change closes its source the
            -- day before.
            CREATE FUNCTION hr_employee_placement_changes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_to_employment uuid;
                v_to_starts date;
                v_from_employment uuid;
                v_from_ends date;
            BEGIN
                SELECT a.employment_record_id, a.starts_on INTO v_to_employment, v_to_starts FROM public.employee_assignments a
                 WHERE a.id = NEW.to_assignment_id AND a.school_id = NEW.school_id;
                IF v_to_employment IS DISTINCT FROM NEW.employment_record_id OR v_to_starts IS DISTINCT FROM NEW.effective_on THEN
                    RAISE EXCEPTION 'a placement change names an assignment of its employment that starts on the effective date (hr_employee_placement_changes_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.from_assignment_id IS NOT NULL THEN
                    SELECT a.employment_record_id, a.ends_on INTO v_from_employment, v_from_ends FROM public.employee_assignments a
                     WHERE a.id = NEW.from_assignment_id AND a.school_id = NEW.school_id;
                    IF v_from_employment IS DISTINCT FROM NEW.employment_record_id
                       OR (NEW.change_kind <> 'primary_correction' AND v_from_ends IS DISTINCT FROM NEW.effective_on - 1) THEN
                        RAISE EXCEPTION 'a dated placement change closes an assignment of its employment the day before (hr_employee_placement_changes_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employee_placement_changes_guard BEFORE INSERT ON employee_placement_changes
                FOR EACH ROW EXECUTE FUNCTION hr_employee_placement_changes_guard();
            CREATE TRIGGER trg_employee_placement_changes_immutable BEFORE UPDATE ON employee_placement_changes
                FOR EACH ROW EXECUTE FUNCTION hr_history_row_immutable();
            SQL);

        TenantRls::enable('employee_placement_changes');
        DB::statement('REVOKE UPDATE, DELETE ON employee_placement_changes FROM school_os_app');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employee_placement_changes_immutable ON employee_placement_changes;
            DROP TRIGGER IF EXISTS trg_employee_placement_changes_guard ON employee_placement_changes;
            DROP FUNCTION IF EXISTS hr_employee_placement_changes_guard();
            SQL);
        TenantRls::disable('employee_placement_changes');
        Schema::dropIfExists('employee_placement_changes');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_hr_departments_no_cycle ON hr_departments;
            DROP FUNCTION IF EXISTS hr_departments_no_cycle();
            DROP TRIGGER IF EXISTS trg_employees_identity_guard ON employees;
            DROP FUNCTION IF EXISTS hr_employees_identity_guard();
            DROP TRIGGER IF EXISTS trg_employee_assignments_primary_no_overlap ON employee_assignments;
            DROP FUNCTION IF EXISTS hr_employee_assignments_primary_no_overlap();
            DROP TRIGGER IF EXISTS trg_employment_records_no_overlap ON employment_records;
            DROP FUNCTION IF EXISTS hr_employment_records_no_overlap();
            DROP TRIGGER IF EXISTS trg_employment_records_terms_projection_guard ON employment_records;
            DROP FUNCTION IF EXISTS hr_employment_records_terms_projection_guard();
            DROP TRIGGER IF EXISTS trg_employment_records_initial_terms ON employment_records;
            DROP FUNCTION IF EXISTS hr_employment_records_initial_terms();
            DROP TRIGGER IF EXISTS trg_employment_terms_project ON employment_terms;
            DROP FUNCTION IF EXISTS hr_employment_terms_project();
            DROP TRIGGER IF EXISTS trg_employment_terms_immutable ON employment_terms;
            DROP FUNCTION IF EXISTS hr_history_row_immutable();
            DROP TRIGGER IF EXISTS trg_employment_terms_append_guard ON employment_terms;
            DROP FUNCTION IF EXISTS hr_employment_terms_append_guard();
            SQL);
        TenantRls::disable('employment_terms');
        Schema::dropIfExists('employment_terms');

        DB::statement('ALTER TABLE employment_records DROP CONSTRAINT IF EXISTS employment_records_staff_type_check');
        DB::statement('ALTER TABLE employment_records DROP CONSTRAINT IF EXISTS employment_records_employment_type_check');
        Schema::table('employment_records', function (Blueprint $table) {
            $table->dropColumn('staff_type');
        });
    }

    /** Refuses to run on data the new invariants would reject; changes nothing. */
    private function preflight(): void
    {
        $problems = [];

        $unmapped = DB::select('SELECT employment_type, count(*) AS n FROM employment_records WHERE employment_type NOT IN ('.self::EMPLOYMENT_TYPES.') GROUP BY employment_type ORDER BY employment_type');
        if ($unmapped !== []) {
            $problems[] = count($unmapped).' employment type value(s) outside the catalogue ('
                .implode(', ', array_map(fn (object $r) => json_encode($r->employment_type).' x'.$r->n, $unmapped))
                .'); map each to one of '.self::EMPLOYMENT_TYPES.' by an explicit, reviewed data fix';
        }

        $employmentOverlaps = (int) DB::selectOne(
            "SELECT count(*) AS n FROM employment_records a JOIN employment_records b
               ON a.employee_id = b.employee_id AND a.id < b.id
              AND a.starts_on <= COALESCE(b.ends_on, 'infinity'::date) AND b.starts_on <= COALESCE(a.ends_on, 'infinity'::date)",
        )->n;
        if ($employmentOverlaps > 0) {
            $problems[] = "{$employmentOverlaps} pair(s) of overlapping employments of one Employee";
        }

        $primaryOverlaps = (int) DB::selectOne(
            "SELECT count(*) AS n FROM employee_assignments a JOIN employee_assignments b
               ON a.employment_record_id = b.employment_record_id AND a.id < b.id AND a.is_primary AND b.is_primary
              AND a.starts_on <= COALESCE(b.ends_on, 'infinity'::date) AND b.starts_on <= COALESCE(a.ends_on, 'infinity'::date)",
        )->n;
        if ($primaryOverlaps > 0) {
            $problems[] = "{$primaryOverlaps} pair(s) of overlapping primary assignments of one employment";
        }

        if ($problems !== []) {
            throw new RuntimeException('HR.1A workforce foundation refused (nothing changed): '.implode('; ', $problems).'.');
        }
    }
};
