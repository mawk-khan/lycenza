<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRX.1 correction (ADR 0065 §22.1, amended): the leave-year start month is
 * PROSPECTIVELY configurable. Materialized leave years stay immutable.
 *
 * - `leave_settings.leave_year_start_month` remains the School's BASE start
 *   month. It may still change freely only while the School has no leave
 *   year and no scheduled change (`trg_leave_settings_lock`). The lock now
 *   also guards INSERT: a School that opened years under the default
 *   (April) without a settings row can no longer insert a different base
 *   month afterwards. HRX.1 checked UPDATE only.
 * - `leave_year_start_changes` (append-only) records each explicit change:
 *   the new start month and the date it takes effect. That date is always
 *   the first day of the new start month. It must fall strictly after every
 *   materialized leave year and every earlier change, so no existing year,
 *   boundary or evidence is ever reinterpreted (`trg_leave_year_start_changes_guard`).
 * - `leave_years.is_transition` marks an explicit, shorter bridging year.
 *   It runs from the last boundary of the old schedule to the day before a
 *   change takes effect, so there is no gap and no overlap. Full years keep
 *   their exact 1-year shape.
 * - `trg_leave_years_guard` additionally refuses a year that disagrees with
 *   the schedule in force:
 *   - a start month other than the one governing its start date;
 *   - a year that crosses a change boundary;
 *   - a transition year that does not end the day before a recorded change.
 *
 * Rollback is refused once a change or a transition year exists (removing
 * either would reinterpret history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_year_start_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->smallInteger('previous_start_month');
            $table->smallInteger('start_month');
            $table->date('effective_from');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE leave_year_start_changes ADD CONSTRAINT leave_year_start_changes_shape_check CHECK ('
            .'start_month BETWEEN 1 AND 12 AND previous_start_month BETWEEN 1 AND 12 AND start_month <> previous_start_month '
            .'AND EXTRACT(DAY FROM effective_from) = 1 AND EXTRACT(MONTH FROM effective_from) = start_month)');

        DB::statement('ALTER TABLE leave_years ADD COLUMN is_transition boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE leave_years DROP CONSTRAINT leave_years_shape_check');
        DB::statement('ALTER TABLE leave_years ADD CONSTRAINT leave_years_shape_check CHECK (starts_on <= ends_on AND start_month BETWEEN 1 AND 12 '
            .'AND EXTRACT(DAY FROM starts_on) = 1 AND EXTRACT(MONTH FROM starts_on) = start_month AND ('
            ."(NOT is_transition AND ends_on = (starts_on + INTERVAL '1 year - 1 day')::date) "
            ."OR (is_transition AND ends_on < (starts_on + INTERVAL '1 year - 1 day')::date AND EXTRACT(DAY FROM ends_on + 1) = 1)))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION leave_settings_lock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- A missing settings row means the default (4), so an INSERT is a change from 4.
                IF NEW.leave_year_start_month IS DISTINCT FROM (CASE WHEN TG_OP = 'INSERT' THEN 4 ELSE OLD.leave_year_start_month END)
                   AND (EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id)
                        OR EXISTS (SELECT 1 FROM leave_year_start_changes WHERE school_id = NEW.school_id)) THEN
                    RAISE EXCEPTION 'leave_settings: once a leave year exists the start month changes only prospectively, through a scheduled change (leave_year_locked)';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION leave_years_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_governing smallint;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'leave_years: a leave year never changes';
                END IF;
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.years:' || NEW.school_id::text, 0));
                IF EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id AND starts_on <= NEW.ends_on AND ends_on >= NEW.starts_on) THEN
                    RAISE EXCEPTION 'leave_years: leave years may not overlap' USING ERRCODE = 'exclusion_violation';
                END IF;
                v_governing := COALESCE(
                    (SELECT start_month FROM leave_year_start_changes WHERE school_id = NEW.school_id AND effective_from <= NEW.starts_on ORDER BY effective_from DESC LIMIT 1),
                    (SELECT leave_year_start_month FROM leave_settings WHERE school_id = NEW.school_id),
                    4);
                IF NEW.start_month <> v_governing THEN
                    RAISE EXCEPTION 'leave_years: a leave year follows the start month in force at its start (leave_year_schedule_mismatch)';
                END IF;
                IF EXISTS (SELECT 1 FROM leave_year_start_changes WHERE school_id = NEW.school_id AND effective_from > NEW.starts_on AND effective_from <= NEW.ends_on) THEN
                    RAISE EXCEPTION 'leave_years: a leave year never crosses a start-month change (leave_year_schedule_mismatch)';
                END IF;
                IF NEW.is_transition AND NOT EXISTS (SELECT 1 FROM leave_year_start_changes WHERE school_id = NEW.school_id AND effective_from = NEW.ends_on + 1) THEN
                    RAISE EXCEPTION 'leave_years: a transition year ends the day before a start-month change (leave_year_schedule_mismatch)';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE FUNCTION leave_year_start_changes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_latest smallint;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.years:' || NEW.school_id::text, 0));
                v_latest := COALESCE(
                    (SELECT start_month FROM leave_year_start_changes WHERE school_id = NEW.school_id ORDER BY effective_from DESC LIMIT 1),
                    (SELECT leave_year_start_month FROM leave_settings WHERE school_id = NEW.school_id),
                    4);
                IF NEW.previous_start_month <> v_latest OR NEW.start_month = v_latest THEN
                    RAISE EXCEPTION 'leave_year_start_changes: a change starts from the start month currently configured and differs from it (leave_year_start_unchanged)';
                END IF;
                IF EXISTS (SELECT 1 FROM leave_year_start_changes WHERE school_id = NEW.school_id AND effective_from >= NEW.effective_from)
                   OR EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id AND ends_on >= NEW.effective_from) THEN
                    RAISE EXCEPTION 'leave_year_start_changes: a change takes effect after every materialized leave year and every earlier change (leave_year_boundary_invalid)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_year_start_changes_guard BEFORE INSERT ON leave_year_start_changes FOR EACH ROW EXECUTE FUNCTION leave_year_start_changes_guard();

            DROP TRIGGER trg_leave_settings_lock ON leave_settings;
            CREATE TRIGGER trg_leave_settings_lock BEFORE INSERT OR UPDATE ON leave_settings FOR EACH ROW EXECUTE FUNCTION leave_settings_lock();
            SQL);

        TenantRls::enable('leave_year_start_changes');
        TenantRls::makeAppendOnly('leave_year_start_changes');
    }

    public function down(): void
    {
        if (DB::table('leave_year_start_changes')->exists() || DB::table('leave_years')->where('is_transition', true)->exists()) {
            throw new RuntimeException('Refusing to roll back: a scheduled leave-year start change or a transition year exists; removing it would reinterpret leave years (HRX.1).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_year_start_changes_guard ON leave_year_start_changes;
            DROP FUNCTION IF EXISTS leave_year_start_changes_guard();
            DROP TRIGGER trg_leave_settings_lock ON leave_settings;

            CREATE OR REPLACE FUNCTION leave_settings_lock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.leave_year_start_month IS DISTINCT FROM OLD.leave_year_start_month
                   AND EXISTS (SELECT 1 FROM leave_years WHERE school_id = NEW.school_id) THEN
                    RAISE EXCEPTION 'leave_settings: the leave-year start month is fixed once a leave year exists (leave_year_locked)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_settings_lock BEFORE UPDATE ON leave_settings FOR EACH ROW EXECUTE FUNCTION leave_settings_lock();

            CREATE OR REPLACE FUNCTION leave_years_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
            SQL);

        DB::statement('ALTER TABLE leave_years DROP CONSTRAINT leave_years_shape_check');
        DB::statement('ALTER TABLE leave_years ADD CONSTRAINT leave_years_shape_check CHECK (starts_on < ends_on AND start_month BETWEEN 1 AND 12 '
            ."AND EXTRACT(DAY FROM starts_on) = 1 AND EXTRACT(MONTH FROM starts_on) = start_month AND ends_on = (starts_on + INTERVAL '1 year - 1 day')::date)");
        DB::statement('ALTER TABLE leave_years DROP COLUMN is_transition');

        TenantRls::disable('leave_year_start_changes');
        Schema::dropIfExists('leave_year_start_changes');
    }
};
