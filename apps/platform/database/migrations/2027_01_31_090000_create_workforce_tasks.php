<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.13 (owner decisions 2026-11-04; ADR 0028 HR.13 amendment): staff tasks.
 *
 * - `workforce_task_categories`: School-configured categories (code, label),
 *   retired, never deleted.
 * - `workforce_task_templates`: a recurring task's identity (code, status).
 * - `workforce_task_template_versions`: the template's terms -- title,
 *   category, completion mode (self | reviewed), a calendar-neutral schedule
 *   (daily on chosen weekdays; weekly on one weekday; monthly on a day 1-28
 *   or the last day; yearly on a month and an existing day, never 29 Feb),
 *   a due offset in calendar days, applicability (staff types, optional
 *   department, optional position: ALL set conditions must match) and the
 *   date it takes effect. Immutable: a change is a new version.
 * - `workforce_tasks`: one obligation of one EMPLOYMENT -- a generated
 *   occurrence (template + occurrence date, unique: idempotent generation)
 *   or a one-off task assigned by a manager or an administrator. Its terms
 *   and its assignee's attribution (staff type, department, position,
 *   Campus on the day it was created) are snapshots, never re-derived.
 * - `workforce_task_events`: the task's append-only timeline; remarks and
 *   feedback live here (bounded text), so nothing is ever overwritten.
 * - `workforce_task_generation_runs`: one resumable run per School and
 *   occurrence date, with its reconciled counts.
 *
 * Backfill: none. Rollback refuses while any task exists.
 */
return new class extends Migration
{
    private const FREQUENCIES = "'daily', 'weekly', 'monthly', 'yearly'";

    private const MODES = "'self', 'reviewed'";

    private const PATHS = "'generated', 'manager', 'administrative'";

    private const STATUSES = "'assigned', 'submitted', 'returned', 'completed', 'cancelled'";

    private const CANCEL_REASONS = "'no_longer_needed', 'assigned_in_error', 'reassigned'";

    private const EVENT_KINDS = "'generated', 'assigned', 'submitted', 'returned', 'completed', 'cancelled', 'reassigned'";

    private const STAFF_TYPES = "'teaching', 'non_teaching', 'outsourced'";

    public function up(): void
    {
        Schema::create('workforce_task_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->string('status', 10)->default('active');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'code']);
            $table->index('created_by_user_id');
        });
        DB::statement("ALTER TABLE workforce_task_categories ADD CONSTRAINT workforce_task_categories_status_check CHECK ((status = 'active' AND retired_at IS NULL) OR (status = 'retired' AND retired_at IS NOT NULL))");

        Schema::create('workforce_task_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('status', 10)->default('active');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('retired_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'code']);
            $table->index('created_by_user_id');
            $table->index('retired_by_user_id');
        });
        DB::statement("ALTER TABLE workforce_task_templates ADD CONSTRAINT workforce_task_templates_status_check CHECK ((status = 'active' AND retired_at IS NULL AND retired_by_user_id IS NULL) OR (status = 'retired' AND retired_at IS NOT NULL AND retired_by_user_id IS NOT NULL))");

        Schema::create('workforce_task_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('template_id');
            $table->unsignedSmallInteger('version_no');
            $table->string('title', 120);
            $table->string('description', 1000)->nullable();
            $table->uuid('category_id');
            $table->string('completion_mode', 10);
            $table->string('frequency', 10);
            $table->unsignedSmallInteger('weekdays')->nullable();
            $table->unsignedSmallInteger('month_day')->nullable();
            $table->boolean('last_day')->default(false);
            $table->unsignedSmallInteger('year_month')->nullable();
            $table->unsignedSmallInteger('due_days');
            $table->boolean('applies_teaching');
            $table->boolean('applies_non_teaching');
            $table->boolean('applies_outsourced');
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->date('effective_from');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['id', 'school_id']);
            $table->unique(['template_id', 'version_no']);
            $table->unique(['template_id', 'effective_from']);
            $table->foreign(['template_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_task_templates')->restrictOnDelete();
            $table->foreign(['category_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_task_categories')->restrictOnDelete();
            $table->foreign(['department_id', 'school_id'])->references(['id', 'school_id'])->on('hr_departments')->restrictOnDelete();
            $table->foreign(['position_id', 'school_id'])->references(['id', 'school_id'])->on('positions')->restrictOnDelete();
            $table->index(['school_id', 'template_id', 'effective_from'], 'workforce_task_template_versions_effective_idx');
            $table->index('created_by_user_id');
        });
        DB::statement('ALTER TABLE workforce_task_template_versions ADD CONSTRAINT workforce_task_template_versions_mode_check CHECK (completion_mode IN ('.self::MODES.'))');
        DB::statement('ALTER TABLE workforce_task_template_versions ADD CONSTRAINT workforce_task_template_versions_applies_check CHECK (applies_teaching OR applies_non_teaching OR applies_outsourced)');
        DB::statement('ALTER TABLE workforce_task_template_versions ADD CONSTRAINT workforce_task_template_versions_due_check CHECK (due_days <= 366)');
        DB::statement('ALTER TABLE workforce_task_template_versions ADD CONSTRAINT workforce_task_template_versions_schedule_check CHECK (
            frequency IN ('.self::FREQUENCIES.') AND (
              (frequency = \'daily\' AND weekdays BETWEEN 1 AND 127 AND month_day IS NULL AND NOT last_day AND year_month IS NULL)
              OR (frequency = \'weekly\' AND weekdays IN (1, 2, 4, 8, 16, 32, 64) AND month_day IS NULL AND NOT last_day AND year_month IS NULL)
              OR (frequency = \'monthly\' AND weekdays IS NULL AND year_month IS NULL AND ((month_day BETWEEN 1 AND 28 AND NOT last_day) OR (month_day IS NULL AND last_day)))
              OR (frequency = \'yearly\' AND weekdays IS NULL AND year_month BETWEEN 1 AND 12 AND (
                   (NOT last_day AND month_day >= 1 AND month_day <= CASE WHEN year_month = 2 THEN 28 WHEN year_month IN (4, 6, 9, 11) THEN 30 ELSE 31 END)
                   OR (last_day AND month_day IS NULL)))
            ))');

        Schema::create('workforce_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('template_id')->nullable();
            $table->uuid('template_version_id')->nullable();
            $table->date('occurrence_on')->nullable();
            $table->string('title', 120);
            $table->string('description', 1000)->nullable();
            $table->uuid('category_id');
            $table->string('completion_mode', 10);
            $table->string('assignment_path', 20);
            $table->foreignUuid('assigned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('available_on');
            $table->date('due_on');
            $table->string('status', 12)->default('assigned');
            $table->unsignedSmallInteger('return_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('section_id')->nullable();
            $table->uuid('subject_offering_id')->nullable();
            $table->string('staff_type', 20)->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->uuid('campus_id')->nullable();
            $table->uuid('reassigned_from_task_id')->nullable();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            // The employment's own history: it goes with its employment (HR evidence purge).
            $table->foreign(['employment_record_id', 'school_id'])->references(['id', 'school_id'])->on('employment_records')->cascadeOnDelete();
            $table->foreign(['template_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_task_templates')->restrictOnDelete();
            $table->foreign(['template_version_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_task_template_versions')->restrictOnDelete();
            $table->foreign(['category_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_task_categories')->restrictOnDelete();
            $table->foreign(['section_id', 'school_id'])->references(['id', 'school_id'])->on('sections')->restrictOnDelete();
            $table->foreign(['subject_offering_id', 'school_id'])->references(['id', 'school_id'])->on('subject_offerings')->restrictOnDelete();
            $table->foreign(['department_id', 'school_id'])->references(['id', 'school_id'])->on('hr_departments')->restrictOnDelete();
            $table->foreign(['position_id', 'school_id'])->references(['id', 'school_id'])->on('positions')->restrictOnDelete();
            $table->foreign(['campus_id', 'school_id'])->references(['id', 'school_id'])->on('campuses')->restrictOnDelete();
            $table->index(['school_id', 'employment_record_id', 'due_on'], 'workforce_tasks_assignee_idx');
            $table->index(['school_id', 'status', 'due_on'], 'workforce_tasks_status_idx');
            $table->index(['school_id', 'assigned_by_user_id', 'due_on'], 'workforce_tasks_assigner_idx');
            $table->index(['school_id', 'template_id'], 'workforce_tasks_template_idx');
            foreach (['template_version_id', 'category_id', 'section_id', 'subject_offering_id', 'department_id', 'position_id', 'campus_id', 'reassigned_from_task_id', 'completed_by_user_id', 'cancelled_by_user_id'] as $column) {
                $table->index($column);
            }
        });
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_reassigned_from_fk FOREIGN KEY (reassigned_from_task_id, school_id) REFERENCES workforce_tasks (id, school_id) ON DELETE SET NULL (reassigned_from_task_id)');
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_mode_check CHECK (completion_mode IN ('.self::MODES.'))');
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_status_check CHECK (status IN ('.self::STATUSES.'))');
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_staff_type_check CHECK (staff_type IS NULL OR staff_type IN ('.self::STAFF_TYPES.'))');
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_dates_check CHECK (due_on >= available_on AND return_count <= 1)');
        DB::statement('ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_path_check CHECK (assignment_path IN ('.self::PATHS.") AND (
            (assignment_path = 'generated' AND template_id IS NOT NULL AND template_version_id IS NOT NULL AND occurrence_on IS NOT NULL AND assigned_by_user_id IS NULL)
            OR (assignment_path <> 'generated' AND template_id IS NULL AND template_version_id IS NULL AND occurrence_on IS NULL AND assigned_by_user_id IS NOT NULL)))");
        DB::statement("ALTER TABLE workforce_tasks ADD CONSTRAINT workforce_tasks_outcome_check CHECK (
            ((status = 'completed') = (completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL))
            AND ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND cancel_reason IN (".self::CANCEL_REASONS.'))))');
        // Idempotent generation: one occurrence per employment, template and date.
        DB::statement('CREATE UNIQUE INDEX workforce_tasks_one_occurrence ON workforce_tasks (employment_record_id, template_id, occurrence_on) WHERE template_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_workforce_tasks_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_subject uuid := public.hr_separation_subject_user(NEW.employment_record_id);
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'assigned' OR NEW.version <> 1 OR NEW.return_count <> 0 OR NEW.completed_by_user_id IS NOT NULL OR NEW.cancelled_by_user_id IS NOT NULL THEN
                        RAISE EXCEPTION 'a staff task is born assigned (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_subject IS NOT NULL AND NEW.assigned_by_user_id = v_subject THEN
                        RAISE EXCEPTION 'nobody assigns a task to themselves (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                -- A purge of the ORIGINAL employment clears only the link back to it (FK SET NULL);
                -- that is the one change allowed without a transition.
                IF OLD.reassigned_from_task_id IS NOT NULL AND NEW.reassigned_from_task_id IS NULL
                    AND ROW(NEW.status, NEW.version) = ROW(OLD.status, OLD.version) THEN
                    RETURN NEW;
                END IF;

                IF ROW(NEW.school_id, NEW.employment_record_id, NEW.template_id, NEW.template_version_id, NEW.occurrence_on, NEW.title, NEW.description,
                        NEW.category_id, NEW.completion_mode, NEW.assignment_path, NEW.assigned_by_user_id, NEW.available_on, NEW.due_on,
                        NEW.section_id, NEW.subject_offering_id, NEW.staff_type, NEW.department_id, NEW.position_id, NEW.campus_id, NEW.reassigned_from_task_id)
                    IS DISTINCT FROM ROW(OLD.school_id, OLD.employment_record_id, OLD.template_id, OLD.template_version_id, OLD.occurrence_on, OLD.title, OLD.description,
                        OLD.category_id, OLD.completion_mode, OLD.assignment_path, OLD.assigned_by_user_id, OLD.available_on, OLD.due_on,
                        OLD.section_id, OLD.subject_offering_id, OLD.staff_type, OLD.department_id, OLD.position_id, OLD.campus_id, OLD.reassigned_from_task_id) THEN
                    RAISE EXCEPTION 'a staff task''s terms and responsibility are immutable (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status IN ('completed', 'cancelled') THEN
                    RAISE EXCEPTION 'a completed or cancelled staff task is final (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                       (OLD.status IN ('assigned', 'returned') AND NEW.status IN ('submitted', 'completed', 'cancelled'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('completed', 'returned', 'cancelled'))) THEN
                    RAISE EXCEPTION 'staff task % cannot become % (hr_workforce_tasks_guard)', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'completed' AND OLD.status IN ('assigned', 'returned') AND (NEW.completion_mode <> 'self' OR NEW.completed_by_user_id IS DISTINCT FROM v_subject) THEN
                    RAISE EXCEPTION 'only a self-completed task is completed by its assignee''s own submission (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status IN ('submitted') AND NEW.completion_mode <> 'reviewed' THEN
                    RAISE EXCEPTION 'only a reviewed task waits for a review (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'submitted' AND NEW.status IN ('completed', 'returned') AND v_subject IS NOT NULL AND NEW.completed_by_user_id IS NOT DISTINCT FROM v_subject THEN
                    RAISE EXCEPTION 'nobody reviews their own task (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'returned' AND NEW.return_count <> OLD.return_count + 1 THEN
                    RAISE EXCEPTION 'a return is counted (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status <> 'returned' AND NEW.return_count <> OLD.return_count THEN
                    RAISE EXCEPTION 'the return count changes only with a return (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'cancelled' AND v_subject IS NOT NULL AND NEW.cancelled_by_user_id = v_subject THEN
                    RAISE EXCEPTION 'nobody cancels their own task (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'every staff task change advances its version (hr_workforce_tasks_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_workforce_tasks_guard BEFORE INSERT OR UPDATE ON workforce_tasks
                FOR EACH ROW EXECUTE FUNCTION hr_workforce_tasks_guard();
            SQL);

        Schema::create('workforce_task_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('task_id');
            $table->string('kind', 20);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->foreign(['task_id', 'school_id'])->references(['id', 'school_id'])->on('workforce_tasks')->cascadeOnDelete();
            $table->index(['school_id', 'task_id', 'occurred_at'], 'workforce_task_events_task_idx');
            $table->index('actor_user_id');
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_workforce_task_events_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_subject uuid := public.hr_separation_subject_user((SELECT t.employment_record_id FROM public.workforce_tasks t WHERE t.id = NEW.task_id));
            BEGIN
                -- The assignee's own account records only its own submission (and a self-completion).
                IF v_subject IS NOT NULL AND NEW.actor_user_id = v_subject AND NEW.kind IN ('assigned', 'returned', 'cancelled', 'reassigned') THEN
                    RAISE EXCEPTION 'nobody assigns, returns, cancels or reassigns their own task (hr_workforce_task_events_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_workforce_task_events_guard BEFORE INSERT ON workforce_task_events
                FOR EACH ROW EXECUTE FUNCTION hr_workforce_task_events_guard();
            SQL);
        DB::statement('ALTER TABLE workforce_task_events ADD CONSTRAINT workforce_task_events_kind_check CHECK (kind IN ('.self::EVENT_KINDS.") AND (note IS NULL OR kind IN ('submitted', 'returned', 'completed')) AND ((kind = 'generated') = (actor_user_id IS NULL)))");

        Schema::create('workforce_task_generation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('occurrence_on');
            $table->string('status', 12)->default('running');
            $table->uuid('cursor')->nullable();
            $table->unsignedInteger('examined')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('existing')->default(0);
            $table->unsignedInteger('not_matched')->default(0);
            $table->foreignUuid('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'occurrence_on']);
            $table->index('started_by_user_id');
        });
        DB::statement("ALTER TABLE workforce_task_generation_runs ADD CONSTRAINT workforce_task_generation_runs_status_check CHECK (status IN ('running', 'completed', 'failed') AND ((status = 'completed') = (finished_at IS NOT NULL)))");

        foreach (['workforce_task_categories', 'workforce_task_templates', 'workforce_task_template_versions', 'workforce_tasks', 'workforce_task_events', 'workforce_task_generation_runs'] as $table) {
            TenantRls::enable($table);
        }
        foreach (['workforce_task_categories', 'workforce_task_templates', 'workforce_tasks', 'workforce_task_generation_runs'] as $table) {
            DB::statement("REVOKE DELETE ON {$table} FROM school_os_app");
        }
        TenantRls::makeAppendOnly('workforce_task_template_versions');
        TenantRls::makeAppendOnly('workforce_task_events');
    }

    public function down(): void
    {
        // Refuses to destroy task evidence (CLAUDE.md rule 10; HR.13 §80).
        if (Schema::hasTable('workforce_tasks') && DB::table('workforce_tasks')->exists()) {
            throw new RuntimeException('HR.13 rollback refused: workforce_tasks holds task evidence. Export it and remove it deliberately first.');
        }
        foreach (['workforce_task_generation_runs', 'workforce_task_events', 'workforce_tasks', 'workforce_task_template_versions', 'workforce_task_templates', 'workforce_task_categories'] as $table) {
            if (Schema::hasTable($table)) {
                TenantRls::disable($table);
            }
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS hr_workforce_tasks_guard(); DROP FUNCTION IF EXISTS hr_workforce_task_events_guard();');
    }
};
