<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.9 (ADR 0065 §28, HR-0A HR-AD-09): leave policy rules and eligibility.
 *
 * Request-timing rules live ON THE POLICY VERSION (`leave_policies`), so the
 * existing immutability trigger (`trg_leave_policies_immutable`, a whole-row
 * comparison) freezes them exactly like the allocation terms:
 * - `min_notice_days`: calendar days between the School-local submission
 *   date and the first requested date (1..366; NULL = no rule);
 * - `max_backdate_days`: calendar days a request may start in the past
 *   (0..366; 0 = never in the past; NULL = no rule);
 * - `max_units_per_request`: chargeable half-day units one request may hold
 *   (1..734; NULL = no rule).
 * Existing policies get NULL everywhere: no behaviour change.
 *
 * `leave_eligibility_rules`: which employments a policy version applies to,
 * by the HR.1A workforce facts on a date -- the closed staff type, the
 * Employee Category and the primary assignment's Department. NULL in a
 * dimension means "any value, including none". Rules belong to the version:
 * - created only with it: `leave_policies.eligibility_rule_count` is fixed at
 *   INSERT (frozen by the policy trigger); a rule beyond that count is
 *   refused, and a deferred constraint trigger refuses a commit where the
 *   version has fewer;
 * - immutable and never deleted (append-only for the runtime role);
 * - never ambiguous: under the School x leave-type eligibility advisory lock,
 *   a rule is refused if an employment could match it AND a rule of another
 *   ACTIVE policy of the same leave type. A retired version's rules are
 *   inert. There is no precedence order (none is authorised).
 *
 * `leave_eligibility_runs`: the append-only header of one resolution run
 * (School, leave type, as-of date, counts). A run only ADDS assignments to
 * employments with none of that type on the date; it never ends, extends
 * or repoints one. `leave_policy_assignments` gains the run and the matched
 * rule as provenance (both or neither; composite keys pin the rule to the
 * assignment's own policy and the run to its leave type). The retention
 * anchor tracks both new links (ADR 0066 §15).
 *
 * No gender, marital, family, health or free-text column (HR-L8, HRX-L1;
 * `LeaveArchitectureGuardTest`). Rules and runs are School configuration
 * (`leave_configuration`, tenant lifetime). Rollback refuses once any rule,
 * run, rule value or provenance exists (it would lose them).
 */
return new class extends Migration
{
    private const STAFF_TYPES = "'teaching', 'non_teaching', 'outsourced'";

    private const ANCHOR_BEFORE = "'created_at', 'updated_at', 'ended_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id'";

    private const ANCHOR_AFTER = "'created_at', 'updated_at', 'ended_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id', 'eligibility_run_id', 'eligibility_rule_id'";

    public function up(): void
    {
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->smallInteger('min_notice_days')->nullable();
            $table->smallInteger('max_backdate_days')->nullable();
            $table->integer('max_units_per_request')->nullable();
            $table->smallInteger('eligibility_rule_count')->default(0);
        });
        DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_rules_check CHECK ('
            .'(min_notice_days IS NULL OR min_notice_days BETWEEN 1 AND 366) '
            .'AND (max_backdate_days IS NULL OR max_backdate_days BETWEEN 0 AND 366) '
            .'AND (max_units_per_request IS NULL OR max_units_per_request BETWEEN 1 AND 734) '
            .'AND eligibility_rule_count BETWEEN 0 AND 20)');
        DB::statement("COMMENT ON COLUMN leave_policies.min_notice_days IS 'HR.9: calendar days from the School-local submission date to the first requested date; NULL = no rule'");
        DB::statement("COMMENT ON COLUMN leave_policies.max_backdate_days IS 'HR.9: calendar days a request may start before the School-local submission date; 0 = never; NULL = no rule'");
        DB::statement("COMMENT ON COLUMN leave_policies.max_units_per_request IS 'HR.9: chargeable half-day units one request may hold; NULL = no rule'");

        Schema::create('leave_eligibility_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_policy_id');
            $table->uuid('leave_type_id');
            $table->string('staff_type', 20)->nullable();
            $table->uuid('employee_category_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'leave_policy_id']);
            $table->index(['school_id', 'leave_type_id'], 'leave_eligibility_rules_type_idx');
            $table->index('employee_category_id');
            $table->index('department_id');
            $table->foreign(['leave_policy_id', 'school_id', 'leave_type_id'], 'leave_eligibility_rules_policy_fk')
                ->references(['id', 'school_id', 'leave_type_id'])->on('leave_policies')->restrictOnDelete();
            $table->foreign(['employee_category_id', 'school_id'], 'leave_eligibility_rules_category_fk')
                ->references(['id', 'school_id'])->on('employee_categories')->restrictOnDelete();
            $table->foreign(['department_id', 'school_id'], 'leave_eligibility_rules_department_fk')
                ->references(['id', 'school_id'])->on('hr_departments')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_eligibility_rules ADD CONSTRAINT leave_eligibility_rules_staff_type_check CHECK (staff_type IS NULL OR staff_type IN ('.self::STAFF_TYPES.'))');
        DB::statement('CREATE UNIQUE INDEX leave_eligibility_rules_unique ON leave_eligibility_rules (leave_policy_id, staff_type, employee_category_id, department_id) NULLS NOT DISTINCT');

        Schema::create('leave_eligibility_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_type_id');
            $table->date('as_of');
            $table->foreignUuid('executed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->integer('assigned_count');
            $table->integer('skipped_count');
            $table->timestamp('executed_at');

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'leave_type_id']);
            $table->index(['school_id', 'leave_type_id', 'as_of'], 'leave_eligibility_runs_type_idx');
            $table->foreign(['leave_type_id', 'school_id'], 'leave_eligibility_runs_type_fk')->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_eligibility_runs ADD CONSTRAINT leave_eligibility_runs_count_check CHECK (assigned_count >= 0 AND skipped_count >= 0)');

        Schema::table('leave_policy_assignments', function (Blueprint $table) {
            $table->uuid('eligibility_run_id')->nullable();
            $table->uuid('eligibility_rule_id')->nullable();
            $table->index('eligibility_run_id');
            $table->index('eligibility_rule_id');
            $table->foreign(['eligibility_run_id', 'school_id', 'leave_type_id'], 'leave_policy_assignments_run_fk')
                ->references(['id', 'school_id', 'leave_type_id'])->on('leave_eligibility_runs')->restrictOnDelete();
            $table->foreign(['eligibility_rule_id', 'school_id', 'leave_policy_id'], 'leave_policy_assignments_rule_fk')
                ->references(['id', 'school_id', 'leave_policy_id'])->on('leave_eligibility_rules')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_policy_assignments ADD CONSTRAINT leave_policy_assignments_provenance_check CHECK ((eligibility_run_id IS NULL) = (eligibility_rule_id IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION leave_eligibility_rules_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_policy leave_policies%ROWTYPE;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'leave_eligibility_rules: an eligibility rule never changes (leave_eligibility_rule_immutable)';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.eligibility:' || NEW.school_id::text || ':' || NEW.leave_type_id::text, 0));
                SELECT * INTO v_policy FROM leave_policies WHERE id = NEW.leave_policy_id AND school_id = NEW.school_id;
                IF v_policy.status IS DISTINCT FROM 'active' THEN
                    RAISE EXCEPTION 'leave_eligibility_rules: only an active policy version takes eligibility rules (leave_eligibility_policy_inactive)';
                END IF;
                IF (SELECT count(*) FROM leave_eligibility_rules WHERE school_id = NEW.school_id AND leave_policy_id = NEW.leave_policy_id) >= v_policy.eligibility_rule_count THEN
                    RAISE EXCEPTION 'leave_eligibility_rules: a version''s rules are fixed when it is created (leave_eligibility_rule_count)';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM leave_eligibility_rules r
                      JOIN leave_policies p ON p.id = r.leave_policy_id AND p.school_id = r.school_id
                     WHERE r.school_id = NEW.school_id AND r.leave_type_id = NEW.leave_type_id
                       AND r.leave_policy_id <> NEW.leave_policy_id AND p.status = 'active'
                       AND (r.staff_type IS NULL OR NEW.staff_type IS NULL OR r.staff_type = NEW.staff_type)
                       AND (r.employee_category_id IS NULL OR NEW.employee_category_id IS NULL OR r.employee_category_id = NEW.employee_category_id)
                       AND (r.department_id IS NULL OR NEW.department_id IS NULL OR r.department_id = NEW.department_id)) THEN
                    RAISE EXCEPTION 'leave_eligibility_rules: an employment could match this rule and another active policy of the leave type (leave_eligibility_overlap)'
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_eligibility_rules_guard BEFORE INSERT OR UPDATE ON leave_eligibility_rules FOR EACH ROW EXECUTE FUNCTION leave_eligibility_rules_guard();

            -- A version holds exactly the rules it declared, checked at commit.
            CREATE FUNCTION leave_policies_rules_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT count(*) FROM leave_eligibility_rules WHERE school_id = NEW.school_id AND leave_policy_id = NEW.id) <> NEW.eligibility_rule_count THEN
                    RAISE EXCEPTION 'leave_policies: a version must hold exactly the eligibility rules it declared (leave_eligibility_rule_count)';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER trg_leave_policies_rules_complete AFTER INSERT ON leave_policies
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION leave_policies_rules_complete();

            CREATE FUNCTION leave_eligibility_runs_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'leave_eligibility_runs: a run header never changes';
            END;
            $$;
            CREATE TRIGGER trg_leave_eligibility_runs_immutable BEFORE UPDATE ON leave_eligibility_runs FOR EACH ROW EXECUTE FUNCTION leave_eligibility_runs_immutable();
            SQL);

        DB::statement('DROP TRIGGER zzz_retention_anchor ON leave_policy_assignments');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON leave_policy_assignments FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_AFTER.')');

        foreach (['leave_eligibility_rules', 'leave_eligibility_runs'] as $table) {
            TenantRls::enable($table);
            TenantRls::makeAppendOnly($table);
        }
    }

    public function down(): void
    {
        $evidence = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM leave_eligibility_rules) AS rules,
                   (SELECT count(*) FROM leave_eligibility_runs) AS runs,
                   (SELECT count(*) FROM leave_policies WHERE min_notice_days IS NOT NULL OR max_backdate_days IS NOT NULL
                        OR max_units_per_request IS NOT NULL OR eligibility_rule_count > 0) AS ruled,
                   (SELECT count(*) FROM leave_policy_assignments WHERE eligibility_run_id IS NOT NULL) AS provenance
            SQL);
        if ((int) $evidence->rules + (int) $evidence->runs + (int) $evidence->ruled + (int) $evidence->provenance > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back HR.9: it would lose %d eligibility rule(s), %d run(s), the rules of %d policy version(s) and the provenance of %d assignment(s).',
                $evidence->rules, $evidence->runs, $evidence->ruled, $evidence->provenance,
            ));
        }

        DB::statement('DROP TRIGGER zzz_retention_anchor ON leave_policy_assignments');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON leave_policy_assignments FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_BEFORE.')');

        Schema::table('leave_policy_assignments', function (Blueprint $table) {
            $table->dropForeign('leave_policy_assignments_run_fk');
            $table->dropForeign('leave_policy_assignments_rule_fk');
        });
        DB::statement('ALTER TABLE leave_policy_assignments DROP CONSTRAINT leave_policy_assignments_provenance_check');
        Schema::table('leave_policy_assignments', function (Blueprint $table) {
            $table->dropIndex(['eligibility_run_id']);
            $table->dropIndex(['eligibility_rule_id']);
            $table->dropColumn(['eligibility_run_id', 'eligibility_rule_id']);
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_eligibility_runs_immutable ON leave_eligibility_runs;
            DROP TRIGGER IF EXISTS trg_leave_policies_rules_complete ON leave_policies;
            DROP TRIGGER IF EXISTS trg_leave_eligibility_rules_guard ON leave_eligibility_rules;
            DROP FUNCTION IF EXISTS leave_eligibility_runs_immutable();
            DROP FUNCTION IF EXISTS leave_policies_rules_complete();
            DROP FUNCTION IF EXISTS leave_eligibility_rules_guard();
            SQL);
        foreach (['leave_eligibility_runs', 'leave_eligibility_rules'] as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }

        DB::statement('ALTER TABLE leave_policies DROP CONSTRAINT leave_policies_rules_check');
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropColumn(['min_notice_days', 'max_backdate_days', 'max_units_per_request', 'eligibility_rule_count']);
        });
    }
};
