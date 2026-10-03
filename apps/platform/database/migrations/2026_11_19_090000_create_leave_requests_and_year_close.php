<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRX.2 (ADR 0065 §5, §6, §23): leave requests, their decisions, the
 * immutable chargeable-day evidence written at approval, and the year close
 * with its post-close reconciliation.
 *
 * Employee evidence (E21-D9, kept with the Employee, RESTRICT):
 * - `leave_requests`:
 *   - one EmploymentRecord, one leave type, `starts_on` + `start_portion`,
 *     `ends_on` + `end_portion` (shape CHECK, §23.1);
 *   - the half-day run is stored as generated integer indices;
 *   - `employee_id` is derived from the EmploymentRecord by trigger, never
 *     by the caller;
 *   - the content is immutable; the status moves only along the closed
 *     graph and only with its decision evidence (and, for `approved` /
 *     `cancelled`, its day evidence and ledger effects) already written;
 *   - live requests of one employment never overlap (trigger, advisory
 *     lock).
 * - `leave_request_days`: the approval snapshot. One row per chargeable
 *   date, with its portion, units, leave year and (tracked types) policy
 *   assignment and policy version. Append-only.
 * - `leave_decisions`: append-only decision evidence. The decider's Employee
 *   and the requester's Employee are derived by trigger, and a CHECK
 *   forbids approving or rejecting one's own request (§23.5).
 * - `leave_year_close_items`: per employment x type, the closing balance,
 *   the policy terms used, carried, lapsed and the carried units' expiry.
 * - `leave_year_close_reconciliations`: one per reversal of a consumption
 *   in a closed year (§23.9).
 *
 * School configuration (tenant lifetime):
 * - `leave_year_closes`: one header per School x leave year.
 *
 * The ledger gains causal links:
 * - `leave_request_id` (consumption, reversal);
 * - `year_close_id` (carry/expiry);
 * - `year_close_reconciliation_id`.
 *
 * Its guard additionally:
 * - serializes on a per-year advisory lock (shared; the close takes it
 *   exclusively);
 * - seals a closed year;
 * - requires a consumption to equal the approval's day evidence for that
 *   year.
 *
 * No medical, free-text or attachment column (ADR 0065 §10).
 */
return new class extends Migration
{
    private const TABLES = [
        'leave_year_close_reconciliations', 'leave_year_close_items', 'leave_year_closes',
        'leave_decisions', 'leave_request_days', 'leave_requests',
    ];

    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->date('starts_on');
            $table->string('start_portion', 16);
            $table->date('ends_on');
            $table->string('end_portion', 16);
            $table->string('reason_code', 32)->nullable();
            $table->integer('submitted_units');
            $table->string('status', 16)->default('submitted');
            $table->foreignUuid('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'employee_id']);
            $table->index(['school_id', 'employment_record_id', 'status'], 'leave_requests_employment_idx');
            $table->index(['school_id', 'status', 'starts_on'], 'leave_requests_status_idx');
            $table->foreign(['employment_record_id', 'school_id'], 'leave_requests_employment_fk')
                ->references(['id', 'school_id'])->on('employment_records')->restrictOnDelete();
            $table->foreign(['employee_id', 'school_id'], 'leave_requests_employee_fk')
                ->references(['id', 'school_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['leave_type_id', 'school_id'], 'leave_requests_type_fk')
                ->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
        });
        // A request is one contiguous run of half-days: day N's first half is 2N, its second half 2N + 1.
        DB::statement("ALTER TABLE leave_requests ADD COLUMN first_half_index integer GENERATED ALWAYS AS (((starts_on - DATE '2000-01-01') * 2) + CASE WHEN start_portion = 'second_half' THEN 1 ELSE 0 END) STORED");
        DB::statement("ALTER TABLE leave_requests ADD COLUMN last_half_index integer GENERATED ALWAYS AS (((ends_on - DATE '2000-01-01') * 2) + CASE WHEN end_portion = 'first_half' THEN 0 ELSE 1 END) STORED");
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_shape_check CHECK ('
            ."status IN ('submitted', 'approved', 'rejected', 'withdrawn', 'cancelled') AND submitted_units > 0 AND starts_on <= ends_on "
            ."AND start_portion IN ('full', 'first_half', 'second_half') AND end_portion IN ('full', 'first_half', 'second_half') "
            ."AND ((starts_on = ends_on AND start_portion = end_portion) OR (starts_on < ends_on AND start_portion IN ('full', 'second_half') AND end_portion IN ('full', 'first_half'))) "
            ."AND (reason_code IS NULL OR reason_code IN ('personal', 'family', 'official_duty', 'other')) "
            .'AND ends_on - starts_on <= 366)');

        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_request_id');
            $table->date('leave_date');
            $table->string('portion', 16);
            $table->smallInteger('units');
            $table->uuid('leave_year_id');
            $table->uuid('leave_policy_assignment_id')->nullable();
            $table->uuid('leave_policy_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['leave_request_id', 'leave_date']);
            $table->foreign(['leave_request_id', 'school_id'], 'leave_request_days_request_fk')->references(['id', 'school_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['leave_year_id', 'school_id'], 'leave_request_days_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
            $table->foreign(['leave_policy_assignment_id', 'school_id'], 'leave_request_days_assignment_fk')->references(['id', 'school_id'])->on('leave_policy_assignments')->restrictOnDelete();
            $table->foreign(['leave_policy_id', 'school_id'], 'leave_request_days_policy_fk')->references(['id', 'school_id'])->on('leave_policies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_request_days ADD CONSTRAINT leave_request_days_shape_check CHECK ('
            ."portion IN ('full', 'first_half', 'second_half') AND ((portion = 'full') = (units = 2)) AND units IN (1, 2) "
            .'AND ((leave_policy_assignment_id IS NULL) = (leave_policy_id IS NULL)))');

        Schema::create('leave_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_request_id');
            $table->uuid('requester_employee_id');
            $table->string('decision', 16);
            $table->string('path', 16);
            $table->foreignUuid('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('decider_employee_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->foreign(['leave_request_id', 'school_id', 'requester_employee_id'], 'leave_decisions_request_fk')
                ->references(['id', 'school_id', 'employee_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['decider_employee_id', 'school_id'], 'leave_decisions_decider_fk')->references(['id', 'school_id'])->on('employees')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_decisions ADD CONSTRAINT leave_decisions_shape_check CHECK ('
            ."decision IN ('approved', 'rejected', 'withdrawn', 'cancelled') AND path IN ('manager', 'administrative') "
            ."AND (path <> 'manager' OR (decision IN ('approved', 'rejected') AND decider_employee_id IS NOT NULL)) "
            ."AND ((decision = 'approved' AND reason_code IS NULL) "
            ."  OR (decision = 'rejected' AND reason_code IN ('staffing_need', 'policy_not_met', 'duplicate_request', 'entered_in_error', 'other')) "
            ."  OR (decision IN ('withdrawn', 'cancelled') AND reason_code IN ('plans_changed', 'entered_in_error', 'administrative_correction', 'other'))))");
        // ADR 0065 §23.5: nobody approves or rejects their own request, on any path.
        DB::statement("ALTER TABLE leave_decisions ADD CONSTRAINT leave_decisions_no_self_decision CHECK (decision NOT IN ('approved', 'rejected') OR decider_employee_id IS DISTINCT FROM requester_employee_id)");
        DB::statement("CREATE UNIQUE INDEX leave_decisions_one_outcome ON leave_decisions (school_id, leave_request_id) WHERE decision IN ('approved', 'rejected', 'withdrawn')");
        DB::statement("CREATE UNIQUE INDEX leave_decisions_one_cancellation ON leave_decisions (school_id, leave_request_id) WHERE decision = 'cancelled'");

        Schema::create('leave_year_closes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_year_id');
            $table->uuid('next_leave_year_id');
            $table->integer('item_count');
            $table->foreignUuid('executed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('executed_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'leave_year_id'], 'leave_year_closes_once');
            $table->foreign(['leave_year_id', 'school_id'], 'leave_year_closes_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
            $table->foreign(['next_leave_year_id', 'school_id'], 'leave_year_closes_next_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_year_closes ADD CONSTRAINT leave_year_closes_shape_check CHECK (item_count >= 0 AND leave_year_id <> next_leave_year_id)');

        Schema::create('leave_year_close_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('year_close_id');
            $table->uuid('employment_record_id');
            $table->uuid('leave_type_id');
            $table->uuid('leave_policy_id')->nullable();
            $table->boolean('carry_forward_allowed');
            $table->integer('carry_forward_cap_units')->nullable();
            $table->integer('closing_units');
            $table->integer('carried_units');
            $table->integer('lapsed_units');
            $table->date('carried_expires_on')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['year_close_id', 'employment_record_id', 'leave_type_id'], 'leave_year_close_items_key');
            $table->foreign(['year_close_id', 'school_id'], 'leave_year_close_items_close_fk')->references(['id', 'school_id'])->on('leave_year_closes')->restrictOnDelete();
            $table->foreign(['employment_record_id', 'school_id'], 'leave_year_close_items_employment_fk')->references(['id', 'school_id'])->on('employment_records')->restrictOnDelete();
            $table->foreign(['leave_type_id', 'school_id'], 'leave_year_close_items_type_fk')->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['leave_policy_id', 'school_id'], 'leave_year_close_items_policy_fk')->references(['id', 'school_id'])->on('leave_policies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_year_close_items ADD CONSTRAINT leave_year_close_items_shape_check CHECK ('
            .'closing_units >= 0 AND carried_units >= 0 AND lapsed_units >= 0 AND carried_units + lapsed_units = closing_units '
            .'AND (carry_forward_allowed OR carried_units = 0) AND (carry_forward_cap_units IS NULL OR carried_units <= carry_forward_cap_units) '
            .'AND (carried_expires_on IS NULL OR carried_units > 0))');

        Schema::create('leave_year_close_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('year_close_item_id');
            $table->uuid('leave_request_id');
            $table->uuid('reversal_entry_id');
            $table->integer('units');
            $table->integer('carried_delta');
            $table->integer('lapsed_delta');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'reversal_entry_id'], 'leave_year_close_reconciliations_once');
            $table->foreign(['year_close_item_id', 'school_id'], 'leave_year_close_reconciliations_item_fk')->references(['id', 'school_id'])->on('leave_year_close_items')->restrictOnDelete();
            $table->foreign(['leave_request_id', 'school_id'], 'leave_year_close_reconciliations_request_fk')->references(['id', 'school_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['reversal_entry_id', 'school_id'], 'leave_year_close_reconciliations_reversal_fk')->references(['id', 'school_id'])->on('leave_ledger_entries')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_year_close_reconciliations ADD CONSTRAINT leave_year_close_reconciliations_shape_check CHECK (units > 0 AND carried_delta >= 0 AND lapsed_delta >= 0 AND carried_delta + lapsed_delta = units)');

        // Ledger: causal links to requests, closes and reconciliations.
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->uuid('leave_request_id')->nullable();
            $table->uuid('year_close_id')->nullable();
            $table->uuid('year_close_reconciliation_id')->nullable();
            $table->foreign(['leave_request_id', 'school_id'], 'leave_ledger_entries_request_fk')->references(['id', 'school_id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['year_close_id', 'school_id'], 'leave_ledger_entries_close_fk')->references(['id', 'school_id'])->on('leave_year_closes')->restrictOnDelete();
            $table->foreign(['year_close_reconciliation_id', 'school_id'], 'leave_ledger_entries_reconciliation_fk')->references(['id', 'school_id'])->on('leave_year_close_reconciliations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_links_check CHECK ('
            ."((kind IN ('consumption', 'reversal')) = (leave_request_id IS NOT NULL)) "
            ."AND ((kind IN ('carry_forward_in', 'carry_forward_out', 'expiry')) = (year_close_id IS NOT NULL)) "
            .'AND (year_close_reconciliation_id IS NULL OR year_close_id IS NOT NULL))');
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_one_consumption ON leave_ledger_entries (school_id, leave_request_id, leave_year_id) WHERE kind = 'consumption'");
        // One close movement per kind and key; a reconciliation adds at most one of each.
        DB::statement('CREATE UNIQUE INDEX leave_ledger_entries_one_close_movement ON leave_ledger_entries (school_id, year_close_id, employment_record_id, leave_type_id, kind) WHERE year_close_id IS NOT NULL AND year_close_reconciliation_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX leave_ledger_entries_one_reconciliation_movement ON leave_ledger_entries (school_id, year_close_reconciliation_id, kind) WHERE year_close_reconciliation_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION leave_requests_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_tracks boolean;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'submitted' THEN
                        RAISE EXCEPTION 'leave_requests: a request starts submitted (leave_request_transition_invalid)';
                    END IF;
                    SELECT employee_id INTO NEW.employee_id FROM employment_records WHERE id = NEW.employment_record_id AND school_id = NEW.school_id;
                    IF NEW.employee_id IS NULL THEN
                        RAISE EXCEPTION 'leave_requests: unknown employment record';
                    END IF;
                    PERFORM pg_advisory_xact_lock(hashtextextended('leave.requests:' || NEW.school_id::text || ':' || NEW.employment_record_id::text, 0));
                    IF EXISTS (SELECT 1 FROM leave_requests r
                                WHERE r.school_id = NEW.school_id AND r.employment_record_id = NEW.employment_record_id
                                  AND r.status IN ('submitted', 'approved')
                                  AND r.first_half_index <= ((NEW.ends_on - DATE '2000-01-01') * 2) + CASE WHEN NEW.end_portion = 'first_half' THEN 0 ELSE 1 END
                                  AND r.last_half_index >= ((NEW.starts_on - DATE '2000-01-01') * 2) + CASE WHEN NEW.start_portion = 'second_half' THEN 1 ELSE 0 END) THEN
                        RAISE EXCEPTION 'leave_requests: the request overlaps a live request of this employment (leave_request_overlap)';
                    END IF;
                    RETURN NEW;
                END IF;

                -- Generated columns are not readable in a BEFORE trigger; they follow the compared base columns.
                IF (to_jsonb(NEW) - 'status' - 'updated_at' - 'first_half_index' - 'last_half_index')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at' - 'first_half_index' - 'last_half_index') THEN
                    RAISE EXCEPTION 'leave_requests: a request never changes after submission (leave_request_immutable)';
                END IF;
                IF NOT ((OLD.status = 'submitted' AND NEW.status IN ('approved', 'rejected', 'withdrawn')) OR (OLD.status = 'approved' AND NEW.status = 'cancelled')) THEN
                    RAISE EXCEPTION 'leave_requests: % -> % is not a permitted transition (leave_request_transition_invalid)', OLD.status, NEW.status;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM leave_decisions WHERE school_id = NEW.school_id AND leave_request_id = NEW.id AND decision = NEW.status) THEN
                    RAISE EXCEPTION 'leave_requests: a transition needs its decision evidence (leave_request_evidence_missing)';
                END IF;
                SELECT tracks_balance INTO v_tracks FROM leave_types WHERE id = NEW.leave_type_id AND school_id = NEW.school_id;
                IF NEW.status = 'approved' THEN
                    IF NOT EXISTS (SELECT 1 FROM leave_request_days WHERE school_id = NEW.school_id AND leave_request_id = NEW.id) THEN
                        RAISE EXCEPTION 'leave_requests: an approval needs its chargeable-day evidence (leave_request_evidence_missing)';
                    END IF;
                    IF v_tracks AND EXISTS (SELECT 1 FROM leave_request_days d WHERE d.school_id = NEW.school_id AND d.leave_request_id = NEW.id
                            AND NOT EXISTS (SELECT 1 FROM leave_ledger_entries e WHERE e.school_id = NEW.school_id AND e.leave_request_id = NEW.id
                                            AND e.kind = 'consumption' AND e.leave_year_id = d.leave_year_id)) THEN
                        RAISE EXCEPTION 'leave_requests: a tracked approval consumes every leave year it charges (leave_request_evidence_missing)';
                    END IF;
                END IF;
                IF NEW.status = 'cancelled' AND EXISTS (SELECT 1 FROM leave_ledger_entries c WHERE c.school_id = NEW.school_id AND c.leave_request_id = NEW.id AND c.kind = 'consumption'
                        AND NOT EXISTS (SELECT 1 FROM leave_ledger_entries r WHERE r.school_id = NEW.school_id AND r.reverses_entry_id = c.id)) THEN
                    RAISE EXCEPTION 'leave_requests: a cancellation reverses every consumption (leave_request_evidence_missing)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_requests_guard BEFORE INSERT OR UPDATE ON leave_requests FOR EACH ROW EXECUTE FUNCTION leave_requests_guard();

            CREATE FUNCTION leave_request_days_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_request leave_requests%ROWTYPE;
            BEGIN
                SELECT * INTO v_request FROM leave_requests WHERE id = NEW.leave_request_id AND school_id = NEW.school_id;
                IF v_request.status IS DISTINCT FROM 'submitted' OR NEW.leave_date < v_request.starts_on OR NEW.leave_date > v_request.ends_on THEN
                    RAISE EXCEPTION 'leave_request_days: evidence is written while approving, inside the request dates (leave_request_evidence_invalid)';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM leave_years WHERE id = NEW.leave_year_id AND school_id = NEW.school_id AND starts_on <= NEW.leave_date AND ends_on >= NEW.leave_date) THEN
                    RAISE EXCEPTION 'leave_request_days: the leave year contains the date (leave_request_evidence_invalid)';
                END IF;
                IF NEW.leave_policy_assignment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM leave_policy_assignments a
                        WHERE a.id = NEW.leave_policy_assignment_id AND a.school_id = NEW.school_id AND a.leave_policy_id = NEW.leave_policy_id
                          AND a.employment_record_id = v_request.employment_record_id AND a.leave_type_id = v_request.leave_type_id
                          AND a.effective_from <= NEW.leave_date AND coalesce(a.effective_to, 'infinity'::date) >= NEW.leave_date) THEN
                    RAISE EXCEPTION 'leave_request_days: the policy assignment is the one effective on the date (leave_request_evidence_invalid)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_request_days_guard BEFORE INSERT ON leave_request_days FOR EACH ROW EXECUTE FUNCTION leave_request_days_guard();

            CREATE FUNCTION leave_decisions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Both Employees are derived here, never trusted from the caller.
                SELECT employee_id INTO NEW.requester_employee_id FROM leave_requests WHERE id = NEW.leave_request_id AND school_id = NEW.school_id;
                SELECT id INTO NEW.decider_employee_id FROM employees WHERE school_id = NEW.school_id AND user_id = NEW.decided_by_user_id;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_decisions_guard BEFORE INSERT ON leave_decisions FOR EACH ROW EXECUTE FUNCTION leave_decisions_guard();

            CREATE OR REPLACE FUNCTION leave_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_balance bigint;
                v_target leave_ledger_entries%ROWTYPE;
                v_closed boolean;
                v_day_units bigint;
            BEGIN
                -- Year first (the close holds it exclusively), then the balance key.
                PERFORM pg_advisory_xact_lock_shared(hashtextextended('leave.year:' || NEW.school_id::text || ':' || NEW.leave_year_id::text, 0));
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.balance:' || NEW.school_id::text || ':' || NEW.employment_record_id::text || ':'
                    || NEW.leave_type_id::text || ':' || NEW.leave_year_id::text, 0));

                v_closed := EXISTS (SELECT 1 FROM leave_year_closes WHERE school_id = NEW.school_id AND leave_year_id = NEW.leave_year_id);
                IF v_closed AND NOT (NEW.kind = 'reversal' OR (NEW.kind IN ('carry_forward_out', 'expiry') AND NEW.year_close_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'leave_ledger_entries: the leave year is closed (leave_year_closed)';
                END IF;

                IF NEW.kind = 'consumption' THEN
                    SELECT coalesce(sum(units), 0) INTO v_day_units FROM leave_request_days
                     WHERE school_id = NEW.school_id AND leave_request_id = NEW.leave_request_id AND leave_year_id = NEW.leave_year_id;
                    IF v_day_units <> NEW.units OR NOT EXISTS (SELECT 1 FROM leave_requests WHERE id = NEW.leave_request_id AND school_id = NEW.school_id
                            AND status = 'submitted' AND employment_record_id = NEW.employment_record_id AND leave_type_id = NEW.leave_type_id) THEN
                        RAISE EXCEPTION 'leave_ledger_entries: a consumption equals its approval''s day evidence for the year (leave_consumption_invalid)';
                    END IF;
                END IF;

                IF NEW.kind = 'reversal' THEN
                    SELECT * INTO v_target FROM leave_ledger_entries WHERE id = NEW.reverses_entry_id AND school_id = NEW.school_id;
                    IF v_target.kind IS DISTINCT FROM 'consumption'
                       OR v_target.employment_record_id <> NEW.employment_record_id OR v_target.leave_type_id <> NEW.leave_type_id
                       OR v_target.leave_year_id <> NEW.leave_year_id OR v_target.units <> NEW.units
                       OR v_target.leave_request_id IS DISTINCT FROM NEW.leave_request_id THEN
                        RAISE EXCEPTION 'leave_ledger_entries: a reversal undoes exactly one consumption of the same balance (leave_reversal_invalid)';
                    END IF;
                END IF;

                SELECT coalesce(sum(CASE
                        WHEN kind IN ('allocation', 'reversal', 'carry_forward_in') THEN units
                        WHEN kind = 'adjustment' AND direction = 'credit' THEN units
                        ELSE -units END), 0)
                  INTO v_balance
                  FROM leave_ledger_entries
                 WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                   AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id;

                v_balance := v_balance + CASE
                        WHEN NEW.kind IN ('allocation', 'reversal', 'carry_forward_in') THEN NEW.units
                        WHEN NEW.kind = 'adjustment' AND NEW.direction = 'credit' THEN NEW.units
                        ELSE -NEW.units END;
                IF v_balance < 0 THEN
                    RAISE EXCEPTION 'leave_ledger_entries: the leave balance cannot go below zero (leave_balance_negative)';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (self::TABLES as $table) {
            TenantRls::enable($table);
        }
        foreach (['leave_request_days', 'leave_decisions', 'leave_year_closes', 'leave_year_close_items', 'leave_year_close_reconciliations'] as $table) {
            TenantRls::makeAppendOnly($table);
        }
        TenantRls::revokeDelete('leave_requests');
    }

    public function down(): void
    {
        if (DB::table('leave_requests')->exists() || DB::table('leave_year_closes')->exists()) {
            throw new RuntimeException('Refusing to roll back: Leave holds request or year-close evidence (HRX.2). Remove it through its retention path first.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_decisions_guard ON leave_decisions;
            DROP TRIGGER IF EXISTS trg_leave_request_days_guard ON leave_request_days;
            DROP TRIGGER IF EXISTS trg_leave_requests_guard ON leave_requests;
            DROP FUNCTION IF EXISTS leave_decisions_guard();
            DROP FUNCTION IF EXISTS leave_request_days_guard();
            DROP FUNCTION IF EXISTS leave_requests_guard();

            CREATE OR REPLACE FUNCTION leave_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_balance bigint;
                v_target leave_ledger_entries%ROWTYPE;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.balance:' || NEW.school_id::text || ':' || NEW.employment_record_id::text || ':'
                    || NEW.leave_type_id::text || ':' || NEW.leave_year_id::text, 0));

                IF NEW.kind = 'reversal' THEN
                    SELECT * INTO v_target FROM leave_ledger_entries WHERE id = NEW.reverses_entry_id AND school_id = NEW.school_id;
                    IF v_target.kind IS DISTINCT FROM 'consumption'
                       OR v_target.employment_record_id <> NEW.employment_record_id OR v_target.leave_type_id <> NEW.leave_type_id
                       OR v_target.leave_year_id <> NEW.leave_year_id OR v_target.units <> NEW.units THEN
                        RAISE EXCEPTION 'leave_ledger_entries: a reversal undoes exactly one consumption of the same balance (leave_reversal_invalid)';
                    END IF;
                END IF;

                SELECT coalesce(sum(CASE
                        WHEN kind IN ('allocation', 'reversal', 'carry_forward_in') THEN units
                        WHEN kind = 'adjustment' AND direction = 'credit' THEN units
                        ELSE -units END), 0)
                  INTO v_balance
                  FROM leave_ledger_entries
                 WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                   AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id;

                v_balance := v_balance + CASE
                        WHEN NEW.kind IN ('allocation', 'reversal', 'carry_forward_in') THEN NEW.units
                        WHEN NEW.kind = 'adjustment' AND NEW.direction = 'credit' THEN NEW.units
                        ELSE -NEW.units END;
                IF v_balance < 0 THEN
                    RAISE EXCEPTION 'leave_ledger_entries: the leave balance cannot go below zero (leave_balance_negative)';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_one_reconciliation_movement');
        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_one_close_movement');
        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_one_consumption');
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_links_check');
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('leave_ledger_entries_reconciliation_fk');
            $table->dropForeign('leave_ledger_entries_close_fk');
            $table->dropForeign('leave_ledger_entries_request_fk');
            $table->dropColumn(['leave_request_id', 'year_close_id', 'year_close_reconciliation_id']);
        });

        foreach (self::TABLES as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
