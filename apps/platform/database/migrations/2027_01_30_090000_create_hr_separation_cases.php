<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.12 (ADR 0028 HR.12 amendment; owner decisions HR-061, 2026-11-04):
 * the separation case -- one person records a resignation, retirement,
 * fixed-term contract end or mutual agreement; ANOTHER person approves or
 * rejects it; approval moves the employment into `notice_period` with a
 * planned last working day; an HR officer finalizes it on or after that day
 * through the existing EmploymentService::end(); the case completes once its
 * mandatory clearance items are cleared and School access is resolved.
 *
 * Dates follow HR.1A: `planned_last_day` and `final_ends_on` are the
 * INCLUSIVE last employed day (`employment_records.ends_on`), a School-local
 * calendar date. `notified_on` is the day notice was given.
 *
 * - `employment_separation_cases`: the case. Terms (employment, reason,
 *   notified_on, requester) are immutable; the lifecycle graph, the evidence
 *   each state needs, maker/checker and "never the subject" are enforced
 *   here (trg_employment_separation_cases_guard). One live case (not
 *   rejected / withdrawn / cancelled) per employment.
 * - `employment_separation_events`: the case timeline, append-only (the
 *   before/after of a planned-day change lives here, never in audit).
 * - `separation_clearance_requirements`: School-configured ADMINISTRATIVE
 *   clearance items (closed responsibility, optional staff type), retired,
 *   never edited or deleted.
 * - `separation_clearance_items`: the snapshot a case takes of the
 *   applicable requirements when approved; pending -> cleared only, final,
 *   never by the subject. There is no waiver (owner decision).
 *
 * `employment_records`: for the runtime role, `notice_period` is entered
 * only from `active` while an approved case exists, and left back to
 * `active` only once no approved case remains
 * (trg_employment_records_separation_notice). Inserting a `notice_period`
 * row stays refused by EmploymentService::CREATE_STATUSES (HR.1A).
 *
 * Backfill: none. Historical separations stay what employment_records and
 * the audit ledger say; no case, decision or clearance is fabricated.
 */
return new class extends Migration
{
    private const REASONS = "'resignation', 'retirement', 'contract_end', 'mutual_agreement'";

    private const STATUSES = "'requested', 'approved', 'rejected', 'withdrawn', 'cancelled', 'finalized', 'completed'";

    private const CANCEL_REASONS = "'resignation_retracted', 'recorded_in_error', 'superseded'";

    private const EVENT_KINDS = "'requested', 'approved', 'rejected', 'withdrawn', 'planned_day_changed', 'cancelled', 'finalized', 'clearance_cleared', 'access_retained', 'completed'";

    private const RESPONSIBILITIES = "'hr', 'school_administration'";

    private const STAFF_TYPES = "'teaching', 'non_teaching', 'outsourced'";

    public function up(): void
    {
        Schema::create('employment_separation_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->string('reason_code', 30);
            $table->date('notified_on');
            $table->date('planned_last_day');
            $table->string('status', 20)->default('requested');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUuid('withdrawn_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 30)->nullable();
            $table->foreignUuid('finalized_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->date('final_ends_on')->nullable();
            $table->foreignUuid('access_retained_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('access_retained_at')->nullable();
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            // The employment's own history: it goes with its employment (HR evidence purge).
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')->cascadeOnDelete();
            $table->index(['school_id', 'status', 'planned_last_day'], 'employment_separation_cases_queue_idx');
            $table->index(['school_id', 'employment_record_id'], 'employment_separation_cases_employment_idx');
            foreach (['requested_by_user_id', 'decided_by_user_id', 'withdrawn_by_user_id', 'cancelled_by_user_id', 'finalized_by_user_id', 'access_retained_by_user_id', 'completed_by_user_id'] as $column) {
                $table->index($column);
            }
        });

        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_reason_check CHECK (reason_code IN ('.self::REASONS.'))');
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_status_check CHECK (status IN ('.self::STATUSES.'))');
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_cancel_reason_check CHECK (cancel_reason IS NULL OR cancel_reason IN ('.self::CANCEL_REASONS.'))');
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_dates_check CHECK (planned_last_day >= notified_on AND (final_ends_on IS NULL OR final_ends_on = planned_last_day))');
        // HR-061: the decider is never the requester (maker/checker backstop).
        DB::statement('ALTER TABLE employment_separation_cases ADD CONSTRAINT employment_separation_cases_sod_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id)');
        DB::statement("CREATE UNIQUE INDEX employment_separation_cases_one_live ON employment_separation_cases (employment_record_id) WHERE status IN ('requested', 'approved', 'finalized', 'completed')");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_separation_subject_user(p_employment uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
                SELECT e.user_id FROM public.employment_records er JOIN public.employees e ON e.id = er.employee_id WHERE er.id = p_employment
            $$;

            CREATE FUNCTION hr_employment_separation_cases_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_subject uuid := public.hr_separation_subject_user(NEW.employment_record_id);
                v_actor uuid;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'requested' OR NEW.version <> 1 OR NEW.decided_by_user_id IS NOT NULL OR NEW.withdrawn_by_user_id IS NOT NULL
                        OR NEW.cancelled_by_user_id IS NOT NULL OR NEW.finalized_by_user_id IS NOT NULL OR NEW.final_ends_on IS NOT NULL
                        OR NEW.access_retained_by_user_id IS NOT NULL OR NEW.completed_by_user_id IS NOT NULL OR NEW.cancel_reason IS NOT NULL THEN
                        RAISE EXCEPTION 'a separation case is born requested (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_subject IS NOT NULL AND NEW.requested_by_user_id = v_subject THEN
                        RAISE EXCEPTION 'nobody records their own separation (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.employment_record_id IS DISTINCT FROM OLD.employment_record_id
                    OR NEW.reason_code IS DISTINCT FROM OLD.reason_code OR NEW.notified_on IS DISTINCT FROM OLD.notified_on
                    OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                    RAISE EXCEPTION 'separation case terms are immutable (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                -- Evidence once written is never rewritten.
                IF (OLD.decided_by_user_id IS NOT NULL AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at))
                    OR (OLD.withdrawn_by_user_id IS NOT NULL AND NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id)
                    OR (OLD.cancelled_by_user_id IS NOT NULL AND (NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id OR NEW.cancel_reason IS DISTINCT FROM OLD.cancel_reason))
                    OR (OLD.finalized_by_user_id IS NOT NULL AND (NEW.finalized_by_user_id IS DISTINCT FROM OLD.finalized_by_user_id OR NEW.final_ends_on IS DISTINCT FROM OLD.final_ends_on))
                    OR (OLD.access_retained_by_user_id IS NOT NULL AND NEW.access_retained_by_user_id IS DISTINCT FROM OLD.access_retained_by_user_id)
                    OR (OLD.completed_by_user_id IS NOT NULL AND NEW.completed_by_user_id IS DISTINCT FROM OLD.completed_by_user_id) THEN
                    RAISE EXCEPTION 'separation case evidence is immutable (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                -- Once approved, the last day is a decided term: only a case not yet decided changes it.
                IF NEW.planned_last_day IS DISTINCT FROM OLD.planned_last_day AND (OLD.status <> 'requested' OR NEW.status <> OLD.status) THEN
                    RAISE EXCEPTION 'the planned last day changes only before the decision (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    IF NOT ((OLD.status = 'requested' AND NEW.status IN ('approved', 'rejected', 'withdrawn'))
                        OR (OLD.status = 'approved' AND NEW.status IN ('cancelled', 'finalized'))
                        OR (OLD.status = 'finalized' AND NEW.status = 'completed')) THEN
                        RAISE EXCEPTION 'separation case % cannot become % (hr_employment_separation_cases_guard)', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status IN ('approved', 'rejected') AND (NEW.decided_by_user_id IS NULL OR NEW.decided_at IS NULL) THEN
                        RAISE EXCEPTION 'a decision records its decider (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'withdrawn' AND (NEW.withdrawn_by_user_id IS NULL OR NEW.withdrawn_at IS NULL) THEN
                        RAISE EXCEPTION 'a withdrawal records who withdrew (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'cancelled' AND (NEW.cancelled_by_user_id IS NULL OR NEW.cancelled_at IS NULL OR NEW.cancel_reason IS NULL) THEN
                        RAISE EXCEPTION 'a cancellation records who and why (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'finalized' AND (NEW.finalized_by_user_id IS NULL OR NEW.finalized_at IS NULL OR NEW.final_ends_on IS NULL) THEN
                        RAISE EXCEPTION 'a finalization records who and the last day (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'finalized' AND NOT EXISTS (
                        SELECT 1 FROM public.employment_records er WHERE er.id = NEW.employment_record_id AND er.ends_on = NEW.final_ends_on
                            AND er.status IN ('separated', 'retired')) THEN
                        RAISE EXCEPTION 'a case is finalized only once its employment ended on its last day (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'completed' AND (NEW.completed_by_user_id IS NULL OR NEW.completed_at IS NULL
                        OR EXISTS (SELECT 1 FROM public.separation_clearance_items i WHERE i.case_id = NEW.id AND i.is_mandatory AND i.status <> 'cleared')) THEN
                        RAISE EXCEPTION 'a case completes only with every mandatory clearance item cleared (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id
                    OR NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id OR NEW.finalized_by_user_id IS DISTINCT FROM OLD.finalized_by_user_id
                    OR NEW.completed_by_user_id IS DISTINCT FROM OLD.completed_by_user_id) THEN
                    RAISE EXCEPTION 'decision evidence is written only with its transition (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.access_retained_by_user_id IS DISTINCT FROM OLD.access_retained_by_user_id AND NEW.status <> 'finalized' THEN
                    RAISE EXCEPTION 'retained School access is recorded on a finalized case (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                -- Never the subject: whoever is newly recorded on the case is not the separating person's own account.
                v_actor := COALESCE(
                    CASE WHEN NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id THEN NEW.decided_by_user_id END,
                    CASE WHEN NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id THEN NEW.withdrawn_by_user_id END,
                    CASE WHEN NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id THEN NEW.cancelled_by_user_id END,
                    CASE WHEN NEW.finalized_by_user_id IS DISTINCT FROM OLD.finalized_by_user_id THEN NEW.finalized_by_user_id END,
                    CASE WHEN NEW.access_retained_by_user_id IS DISTINCT FROM OLD.access_retained_by_user_id THEN NEW.access_retained_by_user_id END,
                    CASE WHEN NEW.completed_by_user_id IS DISTINCT FROM OLD.completed_by_user_id THEN NEW.completed_by_user_id END);
                IF v_subject IS NOT NULL AND v_actor = v_subject THEN
                    RAISE EXCEPTION 'nobody administers their own separation (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'every separation case change advances its version (hr_employment_separation_cases_guard)' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employment_separation_cases_guard BEFORE INSERT OR UPDATE ON employment_separation_cases
                FOR EACH ROW EXECUTE FUNCTION hr_employment_separation_cases_guard();
            SQL);

        Schema::create('employment_separation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('case_id');
            $table->string('kind', 30);
            $table->date('planned_last_day_before')->nullable();
            $table->date('planned_last_day_after')->nullable();
            $table->uuid('clearance_item_id')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->foreign(['case_id', 'school_id'])->references(['id', 'school_id'])->on('employment_separation_cases')->cascadeOnDelete();
            $table->index(['school_id', 'case_id', 'occurred_at'], 'employment_separation_events_case_idx');
            $table->index('actor_user_id');
        });
        DB::statement('ALTER TABLE employment_separation_events ADD CONSTRAINT employment_separation_events_kind_check CHECK (kind IN ('.self::EVENT_KINDS.'))');
        DB::statement("ALTER TABLE employment_separation_events ADD CONSTRAINT employment_separation_events_shape_check CHECK ((kind = 'planned_day_changed') = (planned_last_day_after IS NOT NULL AND planned_last_day_before IS NOT NULL) AND (kind = 'clearance_cleared') = (clearance_item_id IS NOT NULL))");

        Schema::create('separation_clearance_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 120);
            $table->string('responsibility', 30);
            $table->boolean('is_mandatory');
            $table->string('staff_type', 20)->nullable();
            $table->unsignedSmallInteger('sequence');
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
        DB::statement('ALTER TABLE separation_clearance_requirements ADD CONSTRAINT separation_clearance_requirements_responsibility_check CHECK (responsibility IN ('.self::RESPONSIBILITIES.'))');
        DB::statement('ALTER TABLE separation_clearance_requirements ADD CONSTRAINT separation_clearance_requirements_staff_type_check CHECK (staff_type IS NULL OR staff_type IN ('.self::STAFF_TYPES.'))');
        DB::statement("ALTER TABLE separation_clearance_requirements ADD CONSTRAINT separation_clearance_requirements_status_check CHECK ((status = 'active' AND retired_at IS NULL AND retired_by_user_id IS NULL) OR (status = 'retired' AND retired_at IS NOT NULL AND retired_by_user_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_separation_clearance_requirements_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'active' THEN
                        RAISE EXCEPTION 'a clearance requirement is born active (hr_separation_clearance_requirements_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF ROW(NEW.school_id, NEW.code, NEW.label, NEW.responsibility, NEW.is_mandatory, NEW.staff_type, NEW.sequence, NEW.created_by_user_id)
                    IS DISTINCT FROM ROW(OLD.school_id, OLD.code, OLD.label, OLD.responsibility, OLD.is_mandatory, OLD.staff_type, OLD.sequence, OLD.created_by_user_id)
                    OR OLD.status = 'retired' THEN
                    RAISE EXCEPTION 'a clearance requirement is retired, never edited (hr_separation_clearance_requirements_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_separation_clearance_requirements_guard BEFORE INSERT OR UPDATE ON separation_clearance_requirements
                FOR EACH ROW EXECUTE FUNCTION hr_separation_clearance_requirements_guard();
            SQL);

        Schema::create('separation_clearance_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('case_id');
            $table->uuid('requirement_id');
            $table->string('code', 40);
            $table->string('label', 120);
            $table->string('responsibility', 30);
            $table->boolean('is_mandatory');
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 10)->default('pending');
            $table->foreignUuid('cleared_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            $table->unique(['case_id', 'requirement_id']);
            $table->foreign(['case_id', 'school_id'])->references(['id', 'school_id'])->on('employment_separation_cases')->cascadeOnDelete();
            $table->foreign(['requirement_id', 'school_id'])->references(['id', 'school_id'])->on('separation_clearance_requirements')->restrictOnDelete();
            $table->index(['school_id', 'case_id'], 'separation_clearance_items_case_idx');
            $table->index(['school_id', 'requirement_id'], 'separation_clearance_items_requirement_idx');
            $table->index('cleared_by_user_id');
        });
        DB::statement('ALTER TABLE separation_clearance_items ADD CONSTRAINT separation_clearance_items_responsibility_check CHECK (responsibility IN ('.self::RESPONSIBILITIES.'))');
        DB::statement("ALTER TABLE separation_clearance_items ADD CONSTRAINT separation_clearance_items_status_check CHECK ((status = 'pending' AND cleared_at IS NULL AND cleared_by_user_id IS NULL) OR (status = 'cleared' AND cleared_at IS NOT NULL AND cleared_by_user_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hr_separation_clearance_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_case public.employment_separation_cases%ROWTYPE;
                v_req public.separation_clearance_requirements%ROWTYPE;
            BEGIN
                SELECT * INTO v_case FROM public.employment_separation_cases WHERE id = NEW.case_id;
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO v_req FROM public.separation_clearance_requirements WHERE id = NEW.requirement_id;
                    IF NEW.status <> 'pending' OR v_case.status <> 'approved' THEN
                        RAISE EXCEPTION 'clearance items are taken, pending, when a case is approved (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_req.status <> 'active' OR ROW(NEW.code, NEW.label, NEW.responsibility, NEW.is_mandatory, NEW.sequence)
                        IS DISTINCT FROM ROW(v_req.code, v_req.label, v_req.responsibility, v_req.is_mandatory, v_req.sequence) THEN
                        RAISE EXCEPTION 'a clearance item is an exact snapshot of an active requirement (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF ROW(NEW.school_id, NEW.case_id, NEW.requirement_id, NEW.code, NEW.label, NEW.responsibility, NEW.is_mandatory, NEW.sequence)
                    IS DISTINCT FROM ROW(OLD.school_id, OLD.case_id, OLD.requirement_id, OLD.code, OLD.label, OLD.responsibility, OLD.is_mandatory, OLD.sequence)
                    OR OLD.status = 'cleared' THEN
                    RAISE EXCEPTION 'a clearance item is cleared once, never edited (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF v_case.status NOT IN ('approved', 'finalized') THEN
                    RAISE EXCEPTION 'clearance is recorded on an approved or finalized case (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.cleared_by_user_id = public.hr_separation_subject_user(v_case.employment_record_id) THEN
                    RAISE EXCEPTION 'nobody clears their own separation (hr_separation_clearance_items_guard)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_separation_clearance_items_guard BEFORE INSERT OR UPDATE ON separation_clearance_items
                FOR EACH ROW EXECUTE FUNCTION hr_separation_clearance_items_guard();

            CREATE FUNCTION hr_employment_records_separation_notice() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- The application's runtime role is the boundary (rule 26): the admin
                -- connection (migrations, fixtures modelling pre-HR.12 rows) is not refused.
                IF current_user <> 'school_os_app' THEN
                    RETURN NEW;
                END IF;
                IF NEW.status = 'notice_period' AND OLD.status IS DISTINCT FROM 'notice_period' THEN
                    IF OLD.status <> 'active' OR NOT EXISTS (
                        SELECT 1 FROM public.employment_separation_cases c WHERE c.employment_record_id = NEW.id AND c.status = 'approved') THEN
                        RAISE EXCEPTION 'an employment enters notice_period only from active, under an approved separation case (hr_employment_records_separation_notice)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF OLD.status = 'notice_period' AND NEW.status = 'active' THEN
                    IF EXISTS (SELECT 1 FROM public.employment_separation_cases c WHERE c.employment_record_id = NEW.id AND c.status = 'approved') THEN
                        RAISE EXCEPTION 'an employment leaves notice_period for active only once its separation case is cancelled (hr_employment_records_separation_notice)' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_employment_records_separation_notice BEFORE UPDATE OF status ON employment_records
                FOR EACH ROW EXECUTE FUNCTION hr_employment_records_separation_notice();
            SQL);

        foreach (['employment_separation_cases', 'employment_separation_events', 'separation_clearance_requirements', 'separation_clearance_items'] as $table) {
            TenantRls::enable($table);
        }
        // History: never deleted at runtime (the retention purge removes it with its employment).
        DB::statement('REVOKE DELETE ON employment_separation_cases FROM school_os_app');
        DB::statement('REVOKE DELETE ON separation_clearance_items FROM school_os_app');
        DB::statement('REVOKE DELETE ON separation_clearance_requirements FROM school_os_app');
        TenantRls::makeAppendOnly('employment_separation_events');
    }

    public function down(): void
    {
        // Deliberately refuses to destroy separation evidence: rolling back past
        // HR.12 with recorded cases would silently erase approvals, finalizations
        // and clearance (CLAUDE.md rule 10; HR.12 §84).
        if (Schema::hasTable('employment_separation_cases') && DB::table('employment_separation_cases')->exists()) {
            throw new RuntimeException('HR.12 rollback refused: employment_separation_cases holds separation evidence. Export it and remove it deliberately first.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_employment_records_separation_notice ON employment_records;
            DROP FUNCTION IF EXISTS hr_employment_records_separation_notice();
            SQL);
        foreach (['separation_clearance_items', 'separation_clearance_requirements', 'employment_separation_events', 'employment_separation_cases'] as $table) {
            if (Schema::hasTable($table)) {
                TenantRls::disable($table);
            }
        }
        Schema::dropIfExists('separation_clearance_items');
        DB::unprepared('DROP FUNCTION IF EXISTS hr_separation_clearance_items_guard();');
        Schema::dropIfExists('separation_clearance_requirements');
        DB::unprepared('DROP FUNCTION IF EXISTS hr_separation_clearance_requirements_guard();');
        Schema::dropIfExists('employment_separation_events');
        Schema::dropIfExists('employment_separation_cases');
        DB::unprepared('DROP FUNCTION IF EXISTS hr_employment_separation_cases_guard(); DROP FUNCTION IF EXISTS hr_separation_subject_user(uuid);');
    }
};
