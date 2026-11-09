<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C22 (WO-5, D-6, `docs/hr/HR-R1-C21-D6-DECISION.md` §6): the staff calendar becomes
 * effective-dated. Leave owns it.
 *
 * - `staff_working_week_versions`: append-only versions of the week (effective_from, seven portions,
 *   active | withdrawn | superseded, a closed reason, who recorded it and the database's time).
 * - `staff_holidays`: a lifecycle (active | withdrawn), never deleted; runtime DELETE revoked (S-16).
 * - `staff_working_weekdays`: read-only from here on (the runtime role writes nothing to it).
 * - History starts per School on its local date today (D6-3): its current week becomes the first
 *   version, `reason_code = 'cutover'`. Earlier dates are "not recorded"; nothing is backfilled.
 * - Leave's guards: terms frozen, born active, withdrawal only before effect, supersession only by
 *   a same-date replacement, no version or holiday before the history start, a reason for anything
 *   past-dated, `cutover` only from the migration role, READ COMMITTED only.
 * - Staff Attendance's guard (its month lock, rule 147) on the same tables: a past-dated change takes
 *   every month key it touches shared and is refused if any of those months is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A consistent snapshot of every School's week and holidays: no calendar writer runs meanwhile.
        DB::statement('LOCK TABLE staff_working_weekdays, staff_holidays IN EXCLUSIVE MODE');

        Schema::create('staff_working_week_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('effective_from');
            foreach (range(1, 7) as $day) {
                $table->string("weekday_{$day}", 16);
            }
            $table->string('status', 16)->default('active');
            $table->uuid('supersedes_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignUuid('status_changed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'effective_from']);
            $table->foreign(['supersedes_id', 'school_id'], 'staff_working_week_versions_supersedes_fk')
                ->references(['id', 'school_id'])->on('staff_working_week_versions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_portions_check CHECK ('
            .implode(' AND ', array_map(fn ($d) => "weekday_{$d} IN ('full', 'first_half', 'off')", range(1, 7))).')');
        DB::statement("ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_status_check CHECK (status IN ('active', 'withdrawn', 'superseded'))");
        DB::statement("ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_reason_check CHECK (reason_code IS NULL OR reason_code IN ('cutover', 'late_declaration', 'entered_in_error', 'administrative_review'))");
        DB::statement("ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_recorder_check CHECK (recorded_by_user_id IS NOT NULL OR reason_code = 'cutover')");
        DB::statement("ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_status_change_check CHECK ((status = 'active') = (status_changed_at IS NULL))");
        DB::statement("CREATE UNIQUE INDEX staff_working_week_versions_one_active ON staff_working_week_versions (school_id, effective_from) WHERE status = 'active'");
        TenantRls::enable('staff_working_week_versions');
        DB::statement('REVOKE DELETE, TRUNCATE ON staff_working_week_versions FROM school_os_app');

        Schema::table('staff_holidays', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            $table->string('recorded_reason_code', 32)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignUuid('withdrawn_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawn_reason_code', 32)->nullable();
        });
        DB::statement('UPDATE staff_holidays SET recorded_at = created_at');
        DB::statement('ALTER TABLE staff_holidays ALTER COLUMN recorded_at SET NOT NULL');
        DB::statement("ALTER TABLE staff_holidays ADD CONSTRAINT staff_holidays_status_check CHECK (status IN ('active', 'withdrawn') AND ((status = 'withdrawn') = (withdrawn_at IS NOT NULL)))");
        DB::statement("ALTER TABLE staff_holidays ADD CONSTRAINT staff_holidays_reason_check CHECK ((recorded_reason_code IS NULL OR recorded_reason_code IN ('late_declaration', 'entered_in_error', 'administrative_review')) AND (withdrawn_reason_code IS NULL OR withdrawn_reason_code IN ('late_declaration', 'entered_in_error', 'administrative_review')))");
        Schema::table('staff_holidays', fn (Blueprint $table) => $table->dropUnique(['school_id', 'holiday_on']));
        DB::statement("CREATE UNIQUE INDEX staff_holidays_one_active ON staff_holidays (school_id, holiday_on) WHERE status = 'active'");
        DB::statement('REVOKE DELETE, TRUNCATE ON staff_holidays FROM school_os_app');
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON staff_working_weekdays FROM school_os_app');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.staff_calendar_require_read_committed() RETURNS void
                LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF pg_catalog.current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'staff calendar: a calendar write runs at READ COMMITTED (staff_calendar_isolation)' USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            -- The School's own date on the database clock (rule 141): what "past" means for a calendar change.
            CREATE FUNCTION public.staff_calendar_local_today(p_school uuid) RETURNS date
                LANGUAGE sql STABLE SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT (pg_catalog.now() AT TIME ZONE s.timezone)::date FROM public.schools s WHERE s.id = p_school
            $$;

            -- The first date with a recorded week. Before it the calendar is "not recorded" (D6-3).
            CREATE FUNCTION public.staff_calendar_history_start(p_school uuid) RETURNS date
                LANGUAGE sql STABLE SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT min(effective_from) FROM public.staff_working_week_versions WHERE school_id = p_school AND status IN ('active', 'superseded')
            $$;

            CREATE FUNCTION public.staff_working_week_versions_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_today date;
                v_start date;
                v_maintenance boolean := pg_catalog.pg_has_role(current_user, (SELECT c.relowner FROM pg_catalog.pg_class c WHERE c.oid = TG_RELID), 'MEMBER');
            BEGIN
                PERFORM public.staff_calendar_require_read_committed();
                v_today := public.staff_calendar_local_today(NEW.school_id);
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'active' OR NEW.status_changed_by_user_id IS NOT NULL OR NEW.status_changed_at IS NOT NULL THEN
                        RAISE EXCEPTION 'staff_working_week_versions: a version is born active (staff_calendar_version_invalid)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.reason_code = 'cutover' AND NOT v_maintenance THEN
                        RAISE EXCEPTION 'staff_working_week_versions: only the migration records the history start (staff_calendar_cutover)' USING ERRCODE = 'check_violation';
                    END IF;
                    NEW.recorded_at := pg_catalog.now() AT TIME ZONE 'UTC';
                    v_start := public.staff_calendar_history_start(NEW.school_id);
                    -- D6-3: no backfill. A past date needs a history start at or before it; a School without one starts today or later.
                    IF NEW.effective_from < v_today AND NOT v_maintenance AND (v_start IS NULL OR NEW.effective_from < v_start) THEN
                        RAISE EXCEPTION 'staff_working_week_versions: nothing is recorded before the history start (staff_calendar_before_history)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.effective_from < v_today AND NEW.reason_code IS NULL THEN
                        RAISE EXCEPTION 'staff_working_week_versions: a past-dated version needs a reason (staff_calendar_reason_required)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.supersedes_id IS NOT NULL THEN
                        PERFORM 1 FROM public.staff_working_week_versions
                         WHERE id = NEW.supersedes_id AND school_id = NEW.school_id AND status = 'active' AND effective_from = NEW.effective_from FOR UPDATE;
                        IF NOT FOUND THEN
                            RAISE EXCEPTION 'staff_working_week_versions: a replacement supersedes the active version of its own date (staff_calendar_supersede_invalid)' USING ERRCODE = 'check_violation';
                        END IF;
                        PERFORM pg_catalog.set_config('staff_calendar.superseding', NEW.supersedes_id::text, true);
                        UPDATE public.staff_working_week_versions
                           SET status = 'superseded', status_changed_by_user_id = NEW.recorded_by_user_id, status_changed_at = pg_catalog.now() AT TIME ZONE 'UTC'
                         WHERE id = NEW.supersedes_id;
                        PERFORM pg_catalog.set_config('staff_calendar.superseding', '', true);
                    END IF;
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.school_id, NEW.effective_from, NEW.weekday_1, NEW.weekday_2, NEW.weekday_3, NEW.weekday_4, NEW.weekday_5, NEW.weekday_6, NEW.weekday_7,
                    NEW.reason_code, NEW.supersedes_id, NEW.recorded_by_user_id, NEW.recorded_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.school_id, OLD.effective_from, OLD.weekday_1, OLD.weekday_2, OLD.weekday_3, OLD.weekday_4, OLD.weekday_5, OLD.weekday_6, OLD.weekday_7,
                    OLD.reason_code, OLD.supersedes_id, OLD.recorded_by_user_id, OLD.recorded_at) THEN
                    RAISE EXCEPTION 'staff_working_week_versions: the terms of a version are frozen (staff_calendar_immutable)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'active' OR NEW.status = 'active' THEN
                    RAISE EXCEPTION 'staff_working_week_versions: only an active version changes state, and once (staff_calendar_status_final)' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'withdrawn' THEN
                    IF OLD.effective_from <= v_today THEN
                        RAISE EXCEPTION 'staff_working_week_versions: only a version that has not taken effect is withdrawn (staff_calendar_withdraw_effective)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status_changed_by_user_id IS NULL THEN
                        RAISE EXCEPTION 'staff_working_week_versions: a withdrawal names who made it (staff_calendar_version_invalid)' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF pg_catalog.pg_trigger_depth() < 2 OR pg_catalog.current_setting('staff_calendar.superseding', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'staff_working_week_versions: only its replacement supersedes a version (staff_calendar_supersede_invalid)' USING ERRCODE = 'check_violation';
                END IF;
                NEW.status_changed_at := pg_catalog.now() AT TIME ZONE 'UTC';
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_working_week_versions_guard BEFORE INSERT OR UPDATE ON staff_working_week_versions
                FOR EACH ROW EXECUTE FUNCTION staff_working_week_versions_guard();

            CREATE FUNCTION public.staff_holidays_history_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_today date;
                v_start date;
            BEGIN
                PERFORM public.staff_calendar_require_read_committed();
                v_today := public.staff_calendar_local_today(NEW.school_id);
                v_start := public.staff_calendar_history_start(NEW.school_id);
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'active' OR NEW.withdrawn_by_user_id IS NOT NULL OR NEW.withdrawn_at IS NOT NULL OR NEW.withdrawn_reason_code IS NOT NULL THEN
                        RAISE EXCEPTION 'staff_holidays: a holiday is born active (staff_calendar_version_invalid)' USING ERRCODE = 'check_violation';
                    END IF;
                    NEW.recorded_at := pg_catalog.now() AT TIME ZONE 'UTC';
                    IF NEW.holiday_on < v_today AND (v_start IS NULL OR NEW.holiday_on < v_start) THEN
                        RAISE EXCEPTION 'staff_holidays: nothing is recorded before the history start (staff_calendar_before_history)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.holiday_on < v_today AND NEW.recorded_reason_code IS NULL THEN
                        RAISE EXCEPTION 'staff_holidays: a past-dated holiday needs a reason (staff_calendar_reason_required)' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.school_id, NEW.holiday_on, NEW.portion, NEW.name, NEW.created_by_user_id, NEW.created_at, NEW.recorded_at, NEW.recorded_reason_code)
                   IS DISTINCT FROM
                   (OLD.id, OLD.school_id, OLD.holiday_on, OLD.portion, OLD.name, OLD.created_by_user_id, OLD.created_at, OLD.recorded_at, OLD.recorded_reason_code) THEN
                    RAISE EXCEPTION 'staff_holidays: the terms of a holiday are frozen (staff_calendar_immutable)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'active' OR NEW.status <> 'withdrawn' OR NEW.withdrawn_by_user_id IS NULL THEN
                    RAISE EXCEPTION 'staff_holidays: a holiday is only withdrawn, once, by someone (staff_calendar_status_final)' USING ERRCODE = 'check_violation';
                END IF;
                IF v_start IS NOT NULL AND OLD.holiday_on < v_start THEN
                    RAISE EXCEPTION 'staff_holidays: nothing before the history start changes (staff_calendar_before_history)' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.holiday_on <= v_today AND NEW.withdrawn_reason_code IS NULL THEN
                    RAISE EXCEPTION 'staff_holidays: a holiday that has taken effect is withdrawn only as a correction, with a reason (staff_calendar_reason_required)' USING ERRCODE = 'check_violation';
                END IF;
                NEW.withdrawn_at := pg_catalog.now() AT TIME ZONE 'UTC';
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_holidays_history_guard BEFORE INSERT OR UPDATE ON staff_holidays
                FOR EACH ROW EXECUTE FUNCTION staff_holidays_history_guard();

            -- Staff Attendance's month lock over the calendar (rule 147): a change whose answer reaches a past date takes
            -- each month key it touches shared, in month order, and is refused if any of those months is locked.
            CREATE FUNCTION public.staff_attendance_calendar_month_guard() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_today date := public.staff_calendar_local_today(NEW.school_id);
                v_from date;
                v_to date;
                v_month date;
            BEGIN
                IF TG_TABLE_NAME = 'staff_holidays' THEN
                    IF TG_OP = 'UPDATE' AND NOT (OLD.status = 'active' AND NEW.status = 'withdrawn') THEN
                        RETURN NEW;
                    END IF;
                    v_from := NEW.holiday_on;
                    v_to := NEW.holiday_on;
                ELSE
                    IF TG_OP = 'UPDATE' THEN
                        RETURN NEW; -- a withdrawal is future-only; a supersession is covered by its replacement's insert
                    END IF;
                    v_from := NEW.effective_from;
                    SELECT min(effective_from) - 1 INTO v_to FROM public.staff_working_week_versions
                     WHERE school_id = NEW.school_id AND status = 'active' AND effective_from > NEW.effective_from;
                    v_to := COALESCE(v_to, v_today);
                END IF;
                v_to := LEAST(v_to, v_today - 1);
                IF v_from > v_to THEN
                    RETURN NEW;
                END IF;
                FOR v_month IN SELECT pg_catalog.generate_series(pg_catalog.date_trunc('month', v_from), pg_catalog.date_trunc('month', v_to), interval '1 month')::date LOOP
                    PERFORM pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, v_month));
                    IF public.staff_attendance_month_is_locked(NEW.school_id, v_month) THEN
                        RAISE EXCEPTION 'staff_attendance: the calendar of a locked month does not change (staff_attendance_month_locked)' USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_calendar_month_guard BEFORE INSERT OR UPDATE ON staff_working_week_versions
                FOR EACH ROW EXECUTE FUNCTION staff_attendance_calendar_month_guard();
            CREATE TRIGGER trg_staff_attendance_holiday_month_guard BEFORE INSERT OR UPDATE ON staff_holidays
                FOR EACH ROW EXECUTE FUNCTION staff_attendance_calendar_month_guard();

            REVOKE ALL ON FUNCTION public.staff_calendar_require_read_committed() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.staff_calendar_require_read_committed() TO school_os_app;
            REVOKE ALL ON FUNCTION public.staff_calendar_local_today(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.staff_calendar_local_today(uuid) TO school_os_app;
            REVOKE ALL ON FUNCTION public.staff_calendar_history_start(uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.staff_calendar_history_start(uuid) TO school_os_app;
            SQL);

        // D6-3: each School's history starts on its local date today, from its current week. No backfill.
        DB::statement(<<<'SQL'
            INSERT INTO staff_working_week_versions (id, school_id, effective_from, weekday_1, weekday_2, weekday_3, weekday_4, weekday_5, weekday_6, weekday_7, status, reason_code, recorded_at)
            SELECT gen_random_uuid(), w.school_id, (now() AT TIME ZONE s.timezone)::date,
                   max(portion) FILTER (WHERE iso_weekday = 1), max(portion) FILTER (WHERE iso_weekday = 2), max(portion) FILTER (WHERE iso_weekday = 3),
                   max(portion) FILTER (WHERE iso_weekday = 4), max(portion) FILTER (WHERE iso_weekday = 5), max(portion) FILTER (WHERE iso_weekday = 6),
                   max(portion) FILTER (WHERE iso_weekday = 7), 'active', 'cutover', now() AT TIME ZONE 'UTC'
              FROM staff_working_weekdays w JOIN schools s ON s.id = w.school_id
             GROUP BY w.school_id, s.timezone
            HAVING count(DISTINCT iso_weekday) = 7
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP see every School.
        DB::statement('ALTER TABLE staff_working_week_versions NO FORCE ROW LEVEL SECURITY');
        // Rule 143: the holiday check must be atomic with the ALTER that drops its lifecycle columns.
        DB::statement('LOCK TABLE staff_holidays IN ACCESS EXCLUSIVE MODE');
        $recorded = DB::table('staff_working_week_versions')->whereRaw("reason_code IS DISTINCT FROM 'cutover' OR status <> 'active'")->exists();
        $holidays = DB::table('staff_holidays')->where(fn ($q) => $q->where('status', '<>', 'active')->orWhereNotNull('recorded_reason_code'))->exists();
        if ($recorded || $holidays) {
            throw new RuntimeException('Refusing to roll back: the staff calendar has recorded history beyond its cutover (HR.R1-C22, D-6). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_staff_attendance_holiday_month_guard ON staff_holidays;
            DROP TRIGGER trg_staff_attendance_calendar_month_guard ON staff_working_week_versions;
            DROP TRIGGER trg_staff_holidays_history_guard ON staff_holidays;
            DROP TRIGGER trg_staff_working_week_versions_guard ON staff_working_week_versions;
            DROP FUNCTION public.staff_attendance_calendar_month_guard();
            DROP FUNCTION public.staff_holidays_history_guard();
            DROP FUNCTION public.staff_working_week_versions_guard();
            DROP FUNCTION public.staff_calendar_history_start(uuid);
            DROP FUNCTION public.staff_calendar_local_today(uuid);
            DROP FUNCTION public.staff_calendar_require_read_committed();
            SQL);
        DB::statement('GRANT INSERT, UPDATE, DELETE ON staff_working_weekdays TO school_os_app');
        DB::statement('GRANT DELETE ON staff_holidays TO school_os_app');
        DB::statement('DROP INDEX staff_holidays_one_active');
        Schema::table('staff_holidays', function (Blueprint $table) {
            $table->unique(['school_id', 'holiday_on']);
            $table->dropForeign(['withdrawn_by_user_id']);
            $table->dropColumn(['status', 'recorded_reason_code', 'recorded_at', 'withdrawn_by_user_id', 'withdrawn_at', 'withdrawn_reason_code']);
        });
        Schema::dropIfExists('staff_working_week_versions');
    }
};
