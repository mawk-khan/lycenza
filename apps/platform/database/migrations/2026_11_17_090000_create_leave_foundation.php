<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRX.1 (ADR 0065 §4, amended at HRX.1): the Leave foundation. School-owned,
 * forced RLS (CLAUDE.md rules 17, 18), same-School composite foreign keys
 * (rule 70), every invariant the database can hold enforced here.
 *
 * Configuration (tenant lifetime):
 * - `leave_settings`: the School's leave-year start month (1..12, default
 *   4). It can change only while the School has no leave year: a later
 *   change can never move an existing year (`trg_leave_settings_lock`).
 * - `leave_years`: MATERIALIZED leave years. Each row freezes its own
 *   starts_on/ends_on and the start month it was cut from, so evidence
 *   always resolves to the year it was recorded in, whatever the settings
 *   say later. Append-only for the runtime role; never overlapping
 *   (checked under a per-School advisory lock by `trg_leave_years_guard`).
 * - `leave_types`: School-configured (no statutory catalogue). `is_paid`,
 *   `tracks_balance` and `allows_half_day` are independent and freeze once
 *   the type is used by a policy or the ledger. Deactivated, never deleted.
 * - `leave_policies`: one leave type's terms (annual allocation, carry-
 *   forward). Immutable once created; the only change is active -> retired.
 *   A new version supersedes it (`supersedes_policy_id`).
 * - `staff_working_weekdays` / `staff_holidays`: the staff working calendar
 *   Leave owns (no School calendar exists elsewhere). Separate from every
 *   academic calendar.
 * - `leave_allocation_runs`: one header per School x leave year x leave
 *   type; append-only.
 *
 * Employee evidence (E21-D9, kept with the Employee):
 * - `leave_policy_assignments`: a policy on an EmploymentRecord, effective-
 *   dated; no overlapping assignment of the same leave type on one
 *   employment (checked under an advisory lock). The only change is ending
 *   it, never extending or repointing.
 * - `leave_ledger_entries`: the append-only ledger. Kind + POSITIVE integer
 *   half-day units; the sign comes from the kind (and `direction` for an
 *   adjustment). The balance is the SUM of the entries -- there is no
 *   balance column. `trg_leave_ledger_guard` serializes each balance key
 *   (School, employment, type, year) on an advisory transaction lock,
 *   refuses any entry for a type that does not track a balance, and refuses
 *   any debit that would leave the balance below zero -- so no later code
 *   path, raw SQL included, can create a negative balance.
 *
 * No medical, free-text or attachment column exists anywhere here (ADR 0065
 * §10; `LeaveArchitectureGuardTest`). Rollback drops the foundation; it
 * refuses once any ledger evidence exists.
 */
return new class extends Migration
{
    private const KINDS = "'allocation', 'adjustment', 'consumption', 'reversal', 'carry_forward_in', 'carry_forward_out', 'expiry'";

    private const ADJUSTMENT_REASONS = "'entitlement_change', 'allocation_correction', 'administrative_correction'";

    private const TABLES = [
        'leave_ledger_entries', 'leave_allocation_runs', 'leave_policy_assignments', 'leave_policies', 'leave_types',
        'staff_holidays', 'staff_working_weekdays', 'leave_years', 'leave_settings',
    ];

    public function up(): void
    {
        Schema::create('leave_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->unique()->constrained('schools')->cascadeOnDelete();
            $table->smallInteger('leave_year_start_month')->default(4);
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE leave_settings ADD CONSTRAINT leave_settings_month_check CHECK (leave_year_start_month BETWEEN 1 AND 12)');

        Schema::create('leave_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('label', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->smallInteger('start_month');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'starts_on']);
            $table->unique(['school_id', 'label']);
        });
        DB::statement('ALTER TABLE leave_years ADD CONSTRAINT leave_years_shape_check CHECK (starts_on < ends_on AND start_month BETWEEN 1 AND 12 '
            ."AND EXTRACT(DAY FROM starts_on) = 1 AND EXTRACT(MONTH FROM starts_on) = start_month AND ends_on = (starts_on + INTERVAL '1 year - 1 day')::date)");

        Schema::create('leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->boolean('is_paid');
            $table->boolean('tracks_balance');
            $table->boolean('allows_half_day');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'tracks_balance']);
            $table->unique(['school_id', 'code']);
        });
        DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_types_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_code_check CHECK (code = upper(code) AND length(trim(code)) > 0 AND length(trim(name)) > 0)');

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_type_id');
            $table->string('name', 120);
            $table->integer('annual_allocation_units');
            $table->boolean('carry_forward_allowed');
            $table->integer('carry_forward_cap_units')->nullable();
            $table->integer('carry_forward_expiry_days')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('supersedes_policy_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'leave_type_id']);
            $table->index(['school_id', 'leave_type_id']);
            $table->foreign(['leave_type_id', 'school_id'], 'leave_policies_type_fk')->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['supersedes_policy_id', 'school_id', 'leave_type_id'], 'leave_policies_supersedes_fk')
                ->references(['id', 'school_id', 'leave_type_id'])->on('leave_policies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_terms_check CHECK (status IN ('active', 'retired') "
            .'AND annual_allocation_units >= 0 AND length(trim(name)) > 0 '
            .'AND (carry_forward_allowed OR (carry_forward_cap_units IS NULL AND carry_forward_expiry_days IS NULL)) '
            .'AND (NOT carry_forward_allowed OR carry_forward_cap_units IS NOT NULL) '
            .'AND (carry_forward_cap_units IS NULL OR carry_forward_cap_units > 0) '
            .'AND (carry_forward_expiry_days IS NULL OR carry_forward_expiry_days > 0) '
            ."AND ((status = 'retired') = (retired_at IS NOT NULL)) AND (supersedes_policy_id IS NULL OR supersedes_policy_id <> id))");

        Schema::create('leave_policy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('leave_policy_id');
            $table->uuid('leave_type_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'employment_record_id', 'leave_type_id', 'effective_from'], 'leave_policy_assignments_key_idx');
            $table->foreign(['employment_record_id', 'school_id'], 'leave_policy_assignments_employment_fk')
                ->references(['id', 'school_id'])->on('employment_records')->restrictOnDelete();
            $table->foreign(['leave_policy_id', 'school_id', 'leave_type_id'], 'leave_policy_assignments_policy_fk')
                ->references(['id', 'school_id', 'leave_type_id'])->on('leave_policies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_policy_assignments ADD CONSTRAINT leave_policy_assignments_shape_check CHECK ((effective_to IS NULL OR effective_from <= effective_to) '
            .'AND ((ended_at IS NULL) = (ended_by_user_id IS NULL)) AND (ended_at IS NULL OR effective_to IS NOT NULL))');

        Schema::create('staff_working_weekdays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->smallInteger('iso_weekday');
            $table->string('portion', 16);
            $table->foreignUuid('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'iso_weekday']);
        });
        DB::statement("ALTER TABLE staff_working_weekdays ADD CONSTRAINT staff_working_weekdays_check CHECK (iso_weekday BETWEEN 1 AND 7 AND portion IN ('full', 'first_half', 'off'))");

        Schema::create('staff_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('holiday_on');
            $table->string('portion', 16);
            $table->string('name', 120);
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'holiday_on']);
        });
        DB::statement("ALTER TABLE staff_holidays ADD CONSTRAINT staff_holidays_check CHECK (portion IN ('full', 'first_half', 'second_half') AND length(trim(name)) > 0)");

        Schema::create('leave_allocation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_year_id');
            $table->uuid('leave_type_id');
            $table->foreignUuid('executed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->integer('allocated_count');
            $table->timestamp('executed_at');

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'leave_year_id', 'leave_type_id'], 'leave_allocation_runs_once');
            $table->foreign(['leave_year_id', 'school_id'], 'leave_allocation_runs_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
            $table->foreign(['leave_type_id', 'school_id'], 'leave_allocation_runs_type_fk')->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_allocation_runs ADD CONSTRAINT leave_allocation_runs_count_check CHECK (allocated_count >= 0)');

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->uuid('leave_type_id');
            $table->boolean('tracks_balance')->default(true);
            $table->uuid('leave_year_id');
            $table->uuid('leave_policy_assignment_id')->nullable();
            $table->string('kind', 24);
            $table->string('direction', 8)->nullable();
            $table->integer('units');
            $table->string('reason_code', 32)->nullable();
            $table->uuid('allocation_run_id')->nullable();
            $table->uuid('reverses_entry_id')->nullable();
            $table->string('source_type', 32)->nullable();
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'employment_record_id', 'leave_type_id', 'leave_year_id'], 'leave_ledger_entries_balance_idx');
            $table->foreign(['employment_record_id', 'school_id'], 'leave_ledger_entries_employment_fk')
                ->references(['id', 'school_id'])->on('employment_records')->restrictOnDelete();
            // The ledger only ever holds entries of a balance-tracked type: the
            // composite key pins `tracks_balance = true` to the type's own flag.
            $table->foreign(['leave_type_id', 'school_id', 'tracks_balance'], 'leave_ledger_entries_type_fk')
                ->references(['id', 'school_id', 'tracks_balance'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['leave_year_id', 'school_id'], 'leave_ledger_entries_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
            $table->foreign(['leave_policy_assignment_id', 'school_id'], 'leave_ledger_entries_assignment_fk')
                ->references(['id', 'school_id'])->on('leave_policy_assignments')->restrictOnDelete();
            $table->foreign(['allocation_run_id', 'school_id'], 'leave_ledger_entries_run_fk')
                ->references(['id', 'school_id'])->on('leave_allocation_runs')->restrictOnDelete();
            $table->foreign(['reverses_entry_id', 'school_id'], 'leave_ledger_entries_reverses_fk')
                ->references(['id', 'school_id'])->on('leave_ledger_entries')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_shape_check CHECK ('
            .'kind IN ('.self::KINDS.') AND units > 0 AND tracks_balance '
            ."AND ((kind = 'adjustment') = (direction IS NOT NULL)) AND (direction IS NULL OR direction IN ('credit', 'debit')) "
            ."AND ((kind = 'adjustment') = (reason_code IS NOT NULL)) AND (reason_code IS NULL OR reason_code IN (".self::ADJUSTMENT_REASONS.')) '
            ."AND (allocation_run_id IS NULL OR kind = 'allocation') "
            ."AND ((kind = 'reversal') = (reverses_entry_id IS NOT NULL)) "
            .'AND ((source_type IS NULL) = (source_id IS NULL)))');
        // One allocation per employment x type x year; one reversal per entry.
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_one_allocation ON leave_ledger_entries (school_id, employment_record_id, leave_type_id, leave_year_id) WHERE kind = 'allocation'");
        DB::statement('CREATE UNIQUE INDEX leave_ledger_entries_one_reversal ON leave_ledger_entries (school_id, reverses_entry_id) WHERE reverses_entry_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION leave_settings_lock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.leave_year_start_month IS DISTINCT FROM OLD.leave_year_start_month
                   AND EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id) THEN
                    RAISE EXCEPTION 'leave_settings: the leave-year start month is fixed once a leave year exists (leave_year_locked)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_settings_lock BEFORE UPDATE ON leave_settings FOR EACH ROW EXECUTE FUNCTION leave_settings_lock();

            CREATE FUNCTION leave_years_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'leave_years: a leave year never changes';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.years:' || NEW.school_id::text, 0));
                IF EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id AND starts_on <= NEW.ends_on AND ends_on >= NEW.starts_on) THEN
                    RAISE EXCEPTION 'leave_years: leave years may not overlap' USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_years_guard BEFORE INSERT OR UPDATE ON leave_years FOR EACH ROW EXECUTE FUNCTION leave_years_guard();

            CREATE FUNCTION leave_types_freeze() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'leave_types: identity and code never change';
                END IF;
                IF (NEW.is_paid IS DISTINCT FROM OLD.is_paid OR NEW.tracks_balance IS DISTINCT FROM OLD.tracks_balance OR NEW.allows_half_day IS DISTINCT FROM OLD.allows_half_day)
                   AND (EXISTS (SELECT 1 FROM leave_policies WHERE school_id = OLD.school_id AND leave_type_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = OLD.school_id AND leave_type_id = OLD.id)) THEN
                    RAISE EXCEPTION 'leave_types: a type in use keeps its paid, balance and half-day rules (leave_type_in_use)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_types_freeze BEFORE UPDATE ON leave_types FOR EACH ROW EXECUTE FUNCTION leave_types_freeze();

            CREATE FUNCTION leave_policies_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'retired'
                   OR NEW.status <> 'retired'
                   OR (to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at') THEN
                    RAISE EXCEPTION 'leave_policies: a policy is immutable; the only change is retiring it (leave_policy_immutable)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_policies_immutable BEFORE UPDATE ON leave_policies FOR EACH ROW EXECUTE FUNCTION leave_policies_immutable();

            CREATE FUNCTION leave_policy_assignments_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.ended_at IS NOT NULL THEN
                        RAISE EXCEPTION 'leave_policy_assignments: an ended assignment is immutable';
                    END IF;
                    IF NEW.ended_at IS NULL
                       OR (to_jsonb(NEW) - 'effective_to' - 'ended_at' - 'ended_by_user_id' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'effective_to' - 'ended_at' - 'ended_by_user_id' - 'updated_at')
                       OR (OLD.effective_to IS NOT NULL AND NEW.effective_to > OLD.effective_to) THEN
                        RAISE EXCEPTION 'leave_policy_assignments: the only change is ending it, never extending or repointing';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.ended_at IS NOT NULL THEN
                    RAISE EXCEPTION 'leave_policy_assignments: an assignment cannot be created already ended';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.assignment:' || NEW.school_id::text || ':' || NEW.employment_record_id::text || ':' || NEW.leave_type_id::text, 0));
                IF EXISTS (SELECT 1 FROM leave_policy_assignments a
                            WHERE a.school_id = NEW.school_id AND a.employment_record_id = NEW.employment_record_id AND a.leave_type_id = NEW.leave_type_id
                              AND a.effective_from <= coalesce(NEW.effective_to, 'infinity'::date) AND coalesce(a.effective_to, 'infinity'::date) >= NEW.effective_from) THEN
                    RAISE EXCEPTION 'leave_policy_assignments: overlapping assignment of this leave type (leave_assignment_overlap)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_policy_assignments_guard BEFORE INSERT OR UPDATE ON leave_policy_assignments FOR EACH ROW EXECUTE FUNCTION leave_policy_assignments_guard();

            -- The balance invariant: serialize the key, refuse a negative balance.
            CREATE FUNCTION leave_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
            CREATE TRIGGER trg_leave_ledger_guard BEFORE INSERT ON leave_ledger_entries FOR EACH ROW EXECUTE FUNCTION leave_ledger_guard();
            SQL);

        foreach (self::TABLES as $table) {
            TenantRls::enable($table);
        }
        // History and identity tables are never deleted by the runtime role; the
        // ledger, the materialized years and run headers are append-only.
        foreach (['leave_ledger_entries', 'leave_allocation_runs', 'leave_years'] as $table) {
            TenantRls::makeAppendOnly($table);
        }
        foreach (['leave_policy_assignments', 'leave_policies', 'leave_types', 'leave_settings'] as $table) {
            TenantRls::revokeDelete($table);
        }
    }

    public function down(): void
    {
        if (DB::table('leave_ledger_entries')->exists() || DB::table('leave_policy_assignments')->exists()) {
            throw new RuntimeException('Refusing to roll back: Leave holds Employee evidence (HRX.1). Remove it through its retention path first.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_ledger_guard ON leave_ledger_entries;
            DROP TRIGGER IF EXISTS trg_leave_policy_assignments_guard ON leave_policy_assignments;
            DROP TRIGGER IF EXISTS trg_leave_policies_immutable ON leave_policies;
            DROP TRIGGER IF EXISTS trg_leave_types_freeze ON leave_types;
            DROP TRIGGER IF EXISTS trg_leave_years_guard ON leave_years;
            DROP TRIGGER IF EXISTS trg_leave_settings_lock ON leave_settings;
            DROP FUNCTION IF EXISTS leave_ledger_guard();
            DROP FUNCTION IF EXISTS leave_policy_assignments_guard();
            DROP FUNCTION IF EXISTS leave_policies_immutable();
            DROP FUNCTION IF EXISTS leave_types_freeze();
            DROP FUNCTION IF EXISTS leave_years_guard();
            DROP FUNCTION IF EXISTS leave_settings_lock();
            SQL);

        foreach (self::TABLES as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
