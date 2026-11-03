<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.3A: effective-dated reporting relationships replace the live
 * `employee_assignments.manager_assignment_id` pointer (removed: a "current"
 * projection of dated, possibly future-dated, data would drift as days pass
 * with no write to refresh it).
 *
 * `reporting_relationships`: one PRIMARY reporting line of a subordinate
 * EMPLOYMENT to a manager EMPLOYMENT over an INCLUSIVE date range (the HR.1A
 * convention; `ends_on = starts_on - 1` is the empty interval of a line
 * cancelled before it started, ADR 0078). Employment-level, so a transfer or
 * promotion (a new assignment of the same employment) never touches the
 * line, and both employments bound it.
 *
 * Database guarantees (each trigger first takes the School's reporting
 * advisory lock, so concurrent writers serialize and the second reads the
 * first's committed rows):
 * - one non-voided line per subordinate employment on any day;
 * - never oneself, never the same Employee through another employment;
 * - the line lies within BOTH employments' dates;
 * - no cycle on any day (a date-aware recursive walk, depth-bounded);
 * - identity and start are immutable; an end only narrows (a later change
 *   may re-close a line that a removal had already ended); a void is final;
 * - an employment's end is refused while a non-voided line would outlive it.
 *
 * Backfill (cut-over): for each employment, the manager the old pointer gave
 * ON THE CUT-OVER DATE (each School's local today), exactly as ReportingLine
 * resolved it, starts a line on that date. Nothing earlier is reconstructed:
 * the pointer was overwritten in place, so pre-cut-over history is unknown,
 * never fabricated. Unsafe or ambiguous data refuses the migration with a
 * diagnostic and changes nothing.
 */
return new class extends Migration
{
    private const STARTED_VIA = "'assigned', 'changed', 'corrected', 'backfill'";

    private const ENDED_VIA = "'changed', 'removed', 'subordinate_employment_ended', 'manager_employment_ended', 'corrected'";

    private const REASONS = "'organisational_change', 'restructuring', 'employee_transfer', 'manager_departure', 'administrative_correction'";

    private const ANCHOR_TRACKED_BEFORE = "'employment_record_id', 'campus_id', 'department_id', 'manager_assignment_id', 'position_id'";

    private const ANCHOR_TRACKED_AFTER = "'employment_record_id', 'campus_id', 'department_id', 'position_id'";

    public function up(): void
    {
        $backfill = $this->planBackfill();

        Schema::create('reporting_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('subordinate_employment_id');
            $table->uuid('manager_employment_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('started_via', 20);
            $table->string('start_reason', 30)->nullable();
            $table->foreignUuid('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('ended_via', 30)->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'subordinate_employment_id', 'starts_on'], 'reporting_relationships_subordinate_idx');
            $table->index(['school_id', 'manager_employment_id', 'starts_on'], 'reporting_relationships_manager_idx');
            $table->index(['school_id', 'starts_on'], 'reporting_relationships_school_date_idx');
            $table->index('started_by_user_id');
            $table->index('ended_by_user_id');
            $table->index('voided_by_user_id');

            // The subordinate's own history goes with its employment; a line that
            // NAMES a manager blocks that manager's purge (as the old pointer did).
            // NO ACTION, not RESTRICT: checked at the end of the statement, so a
            // whole School's cascade (both sides go) is never refused mid-way.
            $table->foreign(['subordinate_employment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')->cascadeOnDelete();
            $table->foreign(['manager_employment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')->onDelete('no action');
        });
        DB::statement('CREATE INDEX reporting_relationships_open_idx ON reporting_relationships (school_id, subordinate_employment_id) WHERE ends_on IS NULL AND voided_at IS NULL');
        DB::statement('ALTER TABLE reporting_relationships ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_not_self_check CHECK (subordinate_employment_id <> manager_employment_id)');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_range_check CHECK (ends_on IS NULL OR ends_on >= starts_on - 1)');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_started_via_check CHECK (started_via IN ('.self::STARTED_VIA.'))');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_ended_via_check CHECK (ended_via IS NULL OR ended_via IN ('.self::ENDED_VIA.'))');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_reason_check CHECK ((start_reason IS NULL OR start_reason IN ('.self::REASONS.')) AND (end_reason IS NULL OR end_reason IN ('.self::REASONS.')) AND (void_reason IS NULL OR void_reason IN ('.self::REASONS.')))');
        // A person's change names its reason and actor; only the cut-over backfill has none.
        DB::statement("ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_start_shape_check CHECK ((started_via = 'backfill') = (start_reason IS NULL AND started_by_user_id IS NULL))");
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_end_shape_check CHECK ((ended_via IS NULL) = (ended_by_user_id IS NULL) AND (ended_via IS NULL OR ends_on IS NOT NULL))');
        DB::statement('ALTER TABLE reporting_relationships ADD CONSTRAINT reporting_relationships_void_shape_check CHECK ((voided_at IS NULL) = (voided_by_user_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_reporting_relationships_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_sub record;
                v_mgr record;
                v_cycle boolean;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.subordinate_employment_id IS DISTINCT FROM OLD.subordinate_employment_id
                       OR NEW.manager_employment_id IS DISTINCT FROM OLD.manager_employment_id OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                       OR NEW.started_via IS DISTINCT FROM OLD.started_via OR NEW.start_reason IS DISTINCT FROM OLD.start_reason
                       OR NEW.started_by_user_id IS DISTINCT FROM OLD.started_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'a reporting line''s parties and start are immutable (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF OLD.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a voided reporting line is final evidence (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF COALESCE(NEW.ends_on, 'infinity'::date) > COALESCE(OLD.ends_on, 'infinity'::date) THEN
                        RAISE EXCEPTION 'a reporting line''s end only narrows (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW; -- narrowing or voiding never creates an overlap or a cycle
                END IF;

                IF NEW.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a reporting line is created live (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('hr.reporting:' || NEW.school_id::text, 0));

                SELECT employee_id, starts_on, ends_on INTO v_sub FROM public.employment_records
                 WHERE id = NEW.subordinate_employment_id AND school_id = NEW.school_id FOR SHARE;
                SELECT employee_id, starts_on, ends_on INTO v_mgr FROM public.employment_records
                 WHERE id = NEW.manager_employment_id AND school_id = NEW.school_id FOR SHARE;
                IF v_sub.employee_id IS NULL OR v_mgr.employee_id IS NULL THEN
                    RAISE EXCEPTION 'a reporting line joins two employments of its School (hr_reporting_relationships_guard)' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF v_sub.employee_id = v_mgr.employee_id THEN
                    RAISE EXCEPTION 'an Employee never reports to themselves (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.ends_on IS NOT NULL AND NEW.ends_on < NEW.starts_on THEN
                    RETURN NEW; -- an empty interval holds no day
                END IF;
                IF NEW.starts_on < GREATEST(v_sub.starts_on, v_mgr.starts_on)
                   OR COALESCE(NEW.ends_on, 'infinity'::date) > LEAST(COALESCE(v_sub.ends_on, 'infinity'::date), COALESCE(v_mgr.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'a reporting line lies within both employments (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (SELECT 1 FROM public.reporting_relationships r
                            WHERE r.school_id = NEW.school_id AND r.subordinate_employment_id = NEW.subordinate_employment_id AND r.id <> NEW.id
                              AND r.voided_at IS NULL AND (r.ends_on IS NULL OR r.ends_on >= r.starts_on)
                              AND r.starts_on <= COALESCE(NEW.ends_on, 'infinity'::date) AND NEW.starts_on <= COALESCE(r.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'an employment has at most one reporting line on any day (hr_reporting_relationships_guard)' USING ERRCODE = 'exclusion_violation';
                END IF;
                -- Date-aware cycle walk: follow the managers' own lines over the intersecting dates.
                WITH RECURSIVE walk(node, lo, hi, depth) AS (
                    SELECT NEW.manager_employment_id, NEW.starts_on, COALESCE(NEW.ends_on, 'infinity'::date), 0
                    UNION ALL
                    SELECT r.manager_employment_id, GREATEST(w.lo, r.starts_on), LEAST(w.hi, COALESCE(r.ends_on, 'infinity'::date)), w.depth + 1
                      FROM walk w JOIN public.reporting_relationships r
                        ON r.school_id = NEW.school_id AND r.subordinate_employment_id = w.node AND r.voided_at IS NULL
                       AND r.starts_on <= w.hi AND COALESCE(r.ends_on, 'infinity'::date) >= w.lo AND (r.ends_on IS NULL OR r.ends_on >= r.starts_on)
                     WHERE w.depth < 1000 AND w.node <> NEW.subordinate_employment_id
                )
                SELECT EXISTS (SELECT 1 FROM walk WHERE node = NEW.subordinate_employment_id) INTO v_cycle;
                IF v_cycle THEN
                    RAISE EXCEPTION 'a reporting line never loops (hr_reporting_relationships_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_reporting_relationships_guard BEFORE INSERT OR UPDATE ON reporting_relationships
                FOR EACH ROW EXECUTE FUNCTION hr_reporting_relationships_guard();

            -- An employment's dates never move so that a live reporting line (either side) falls outside them.
            -- The line's insert share-locks both employment rows, so this UPDATE (which holds the row) and a
            -- concurrent insert serialize, and whichever runs second sees the other's committed row.
            CREATE FUNCTION hr_employment_records_reporting_bounds() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.ends_on IS DISTINCT FROM OLD.ends_on OR NEW.starts_on IS DISTINCT FROM OLD.starts_on) AND EXISTS (
                    SELECT 1 FROM public.reporting_relationships r
                     WHERE r.school_id = NEW.school_id AND r.voided_at IS NULL
                       AND (r.subordinate_employment_id = NEW.id OR r.manager_employment_id = NEW.id)
                       AND (r.ends_on IS NULL OR r.ends_on >= r.starts_on)
                       AND (r.starts_on < NEW.starts_on OR COALESCE(r.ends_on, 'infinity'::date) > COALESCE(NEW.ends_on, 'infinity'::date))) THEN
                    RAISE EXCEPTION 'close the reporting lines of an employment before ending it (hr_employment_records_reporting_bounds)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_employment_records_reporting_bounds BEFORE UPDATE ON employment_records
                FOR EACH ROW EXECUTE FUNCTION hr_employment_records_reporting_bounds();
            SQL);

        foreach ($backfill as $row) {
            DB::table('reporting_relationships')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        TenantRls::enable('reporting_relationships');
        DB::statement('REVOKE DELETE ON reporting_relationships FROM school_os_app');

        // The old pointer goes; the retention anchor stops tracking it.
        DB::statement('DROP TRIGGER zzz_retention_anchor ON employee_assignments');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON employee_assignments FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_TRACKED_AFTER.')');
        DB::statement('ALTER TABLE employee_assignments DROP CONSTRAINT IF EXISTS employee_assignments_no_self_report_check');
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropForeign(['manager_assignment_id', 'school_id']);
            $table->dropIndex(['manager_assignment_id']);
            $table->dropColumn('manager_assignment_id');
        });

        $this->replaceReferenceProbe(columnAware: true);
    }

    public function down(): void
    {
        $this->replaceReferenceProbe(columnAware: false);

        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->uuid('manager_assignment_id')->nullable()->after('position_id');
            $table->index('manager_assignment_id');
        });
        // The ORG.1A shape (2026_12_18): clear only the relationship column, never school_id.
        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_manager_assignment_id_school_id_foreign FOREIGN KEY (manager_assignment_id, school_id) REFERENCES employee_assignments (id, school_id) ON DELETE SET NULL (manager_assignment_id)');
        DB::statement('ALTER TABLE employee_assignments ADD CONSTRAINT employee_assignments_no_self_report_check CHECK (manager_assignment_id IS NULL OR manager_assignment_id <> id)');
        DB::statement('DROP TRIGGER zzz_retention_anchor ON employee_assignments');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON employee_assignments FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_TRACKED_BEFORE.')');

        // Best effort (documented): each line current on the School-local today
        // becomes a pointer from the subordinate's current primary assignment to
        // the manager's current primary assignment. Dated history cannot be held
        // by the old schema and is lost on rollback.
        DB::statement(<<<'SQL'
            UPDATE employee_assignments sa SET manager_assignment_id = m.manager_assignment
              FROM (
                SELECT DISTINCT ON (r.subordinate_employment_id) r.subordinate_employment_id AS sub, ma.id AS manager_assignment
                  FROM reporting_relationships r
                  JOIN schools s ON s.id = r.school_id
                  JOIN employee_assignments ma ON ma.employment_record_id = r.manager_employment_id AND ma.school_id = r.school_id
                 WHERE r.voided_at IS NULL
                   AND r.starts_on <= (now() AT TIME ZONE s.timezone)::date AND COALESCE(r.ends_on, 'infinity'::date) >= (now() AT TIME ZONE s.timezone)::date
                   AND ma.starts_on <= (now() AT TIME ZONE s.timezone)::date AND COALESCE(ma.ends_on, 'infinity'::date) >= (now() AT TIME ZONE s.timezone)::date
                 ORDER BY r.subordinate_employment_id, ma.is_primary DESC, ma.id
              ) m
             WHERE sa.employment_record_id = m.sub AND sa.is_primary AND sa.ends_on IS NULL
            SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employment_records_reporting_bounds ON employment_records;
            DROP FUNCTION IF EXISTS hr_employment_records_reporting_bounds();
            DROP TRIGGER IF EXISTS trg_reporting_relationships_guard ON reporting_relationships;
            DROP FUNCTION IF EXISTS hr_reporting_relationships_guard();
            SQL);
        TenantRls::disable('reporting_relationships');
        Schema::dropIfExists('reporting_relationships');
    }

    /**
     * The cut-over lines, refusing unsafe or ambiguous pointer data. Mirrors
     * ReportingLine's pointer rule on each School's local today: the
     * subordinate assignment is the employment's only open one, or its open
     * primary; the pointer must name an assignment open that day.
     *
     * @return list<array<string, mixed>>
     */
    private function planBackfill(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH day AS (SELECT id AS school_id, (now() AT TIME ZONE timezone)::date AS d FROM schools),
            open_assignments AS (
                SELECT a.*, day.d FROM employee_assignments a JOIN day ON day.school_id = a.school_id
                 WHERE a.starts_on <= day.d AND COALESCE(a.ends_on, 'infinity'::date) >= day.d
            ),
            per_employment AS (
                SELECT employment_record_id, count(*) AS open_count, count(*) FILTER (WHERE is_primary) AS primary_count,
                       count(*) FILTER (WHERE manager_assignment_id IS NOT NULL) AS pointer_count,
                       count(DISTINCT manager_assignment_id) FILTER (WHERE manager_assignment_id IS NOT NULL) AS distinct_pointers
                  FROM open_assignments GROUP BY employment_record_id
            )
            SELECT oa.school_id, oa.employment_record_id AS sub_employment, oa.manager_assignment_id, oa.d,
                   pe.open_count, pe.primary_count, pe.pointer_count, pe.distinct_pointers,
                   ma.employment_record_id AS mgr_employment,
                   (ma.id IS NOT NULL AND ma.starts_on <= oa.d AND COALESCE(ma.ends_on, 'infinity'::date) >= oa.d) AS manager_open,
                   se.employee_id AS sub_employee, se.starts_on AS sub_starts, se.ends_on AS sub_ends,
                   me.employee_id AS mgr_employee, me.starts_on AS mgr_starts, me.ends_on AS mgr_ends
              FROM open_assignments oa
              JOIN per_employment pe ON pe.employment_record_id = oa.employment_record_id
              JOIN employment_records se ON se.id = oa.employment_record_id
              LEFT JOIN employee_assignments ma ON ma.id = oa.manager_assignment_id
              LEFT JOIN employment_records me ON me.id = ma.employment_record_id
             WHERE oa.manager_assignment_id IS NOT NULL AND (pe.open_count = 1 OR oa.is_primary)
             ORDER BY oa.school_id, oa.employment_record_id
            SQL);

        $problems = [];
        $ambiguous = DB::selectOne(<<<'SQL'
            WITH day AS (SELECT id AS school_id, (now() AT TIME ZONE timezone)::date AS d FROM schools)
            SELECT count(*) AS n FROM (
                SELECT a.employment_record_id FROM employee_assignments a JOIN day ON day.school_id = a.school_id
                 WHERE a.starts_on <= day.d AND COALESCE(a.ends_on, 'infinity'::date) >= day.d
                 GROUP BY a.employment_record_id
                HAVING count(DISTINCT a.manager_assignment_id) FILTER (WHERE a.manager_assignment_id IS NOT NULL) > 1
                    OR (count(*) > 1 AND count(*) FILTER (WHERE a.is_primary) = 0 AND count(a.manager_assignment_id) > 0)
            ) x
            SQL)->n;
        if ((int) $ambiguous > 0) {
            $problems[] = "{$ambiguous} employment(s) whose open assignments name conflicting managers, or several open assignments with none primary (ambiguous)";
        }
        $future = DB::selectOne(<<<'SQL'
            WITH day AS (SELECT id AS school_id, (now() AT TIME ZONE timezone)::date AS d FROM schools)
            SELECT count(*) AS n FROM employee_assignments a JOIN day ON day.school_id = a.school_id
             WHERE a.manager_assignment_id IS NOT NULL AND a.starts_on > day.d
            SQL)->n;
        if ((int) $future > 0) {
            $problems[] = "{$future} future-starting assignment(s) with a manager pointer (record them as dated reporting lines after the cut-over)";
        }

        $plan = [];
        foreach ($rows as $r) {
            if (! $r->manager_open) {
                continue; // the pointer named no assignment open today: no current line (the old reader gave none)
            }
            if ($r->sub_employee === $r->mgr_employee) {
                $problems[] = "employment {$r->sub_employment}: reports to the same Employee";

                continue;
            }
            $start = max((string) $r->d, (string) $r->sub_starts, (string) $r->mgr_starts);
            if (($r->sub_ends !== null && (string) $r->sub_ends < $start) || ($r->mgr_ends !== null && (string) $r->mgr_ends < $start)) {
                $problems[] = "employment {$r->sub_employment}: the subordinate or the manager is not employed on the cut-over date";

                continue;
            }
            $ends = array_filter([$r->sub_ends, $r->mgr_ends]);
            $plan[] = [
                'id' => (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id,
                'school_id' => $r->school_id,
                'subordinate_employment_id' => $r->sub_employment,
                'manager_employment_id' => $r->mgr_employment,
                'starts_on' => $start,
                'ends_on' => $ends === [] ? null : min(array_map('strval', $ends)),
                'started_via' => 'backfill',
                'start_reason' => null,
                'started_by_user_id' => null,
            ];
        }

        if ($problems !== []) {
            throw new RuntimeException('HR.3A reporting relationships refused (nothing changed): '.implode('; ', array_slice($problems, 0, 20)).(count($problems) > 20 ? '; ...' : '').'.');
        }

        return $plan;
    }

    /**
     * HR.3A: `retention_first_reference()` also skips a handled `table.column`
     * reference, so a purge can treat the subordinate side of
     * `reporting_relationships` as its own history while the manager side
     * still blocks. Same signature, attributes and grants.
     */
    private function replaceReferenceProbe(bool $columnAware): void
    {
        $skip = $columnAware
            ? "CONTINUE WHEN v_ref.tbl = ANY (coalesce(p_handled, '{}')) OR (v_ref.tbl || '.' || v_ref.col) = ANY (coalesce(p_handled, '{}'));"
            : "CONTINUE WHEN v_ref.tbl = ANY (coalesce(p_handled, '{}'));";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION retention_first_reference(p_parent text, p_school_id uuid, p_ids uuid[], p_handled text[]) RETURNS text
                LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_ref record;
                v_hit boolean;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                IF p_ids IS NULL OR cardinality(p_ids) = 0 THEN
                    RETURN NULL;
                END IF;
                FOR v_ref IN
                    SELECT DISTINCT cl.relname::text AS tbl, a.attname AS col,
                           EXISTS (SELECT 1 FROM pg_attribute s WHERE s.attrelid = c.conrelid AND s.attname = 'school_id' AND NOT s.attisdropped) AS tenant
                      FROM pg_constraint c
                      JOIN pg_class cl ON cl.oid = c.conrelid
                      CROSS JOIN LATERAL unnest(c.conkey, c.confkey) AS k(src, dst)
                      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.src
                      JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.dst
                     WHERE c.contype = 'f' AND c.confrelid = ('public.' || p_parent)::regclass AND fa.attname = 'id'
                     ORDER BY 1, 2
                LOOP
                    {$skip}
                    IF v_ref.tenant THEN
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE %I = ANY ($1) AND school_id = $2)', v_ref.tbl, v_ref.col) INTO v_hit USING p_ids, p_school_id;
                    ELSE
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE %I = ANY ($1))', v_ref.tbl, v_ref.col) INTO v_hit USING p_ids;
                    END IF;
                    IF v_hit THEN
                        RETURN v_ref.tbl;
                    END IF;
                END LOOP;
                RETURN NULL;
            END;
            \$\$;
            SQL);
    }
};
