<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C18 (WO-6; S-6 decided in HR.R1-C17, `docs/hr/HR-R1-C17-S6-DECISION.md`).
 *
 * A School-local calendar month of Staff Attendance can be locked, and is never reopened.
 * A locked month changes only by a locked-month correction: requested by one person,
 * approved by another, and applied here, by the database, from the request's own values.
 *
 * - **Serialization.** Every write to a month's records takes `hr.staff_month:{school}:{YYYY-MM}`
 *   SHARED (these triggers take it too, so a raw write serializes as well), and a lock takes it
 *   EXCLUSIVELY. Work in flight finishes before a lock commits; work after it sees it.
 * - **Who acted stays an application boundary** (rule 102): the database checks that the
 *   recorded locker, requester and decider qualify and are independent; it cannot prove who
 *   pressed the button or that an MFA code was given. The fresh code is enforced in the service.
 * - **Retention:** lock rows hold no personal data and stay with the School. Requests are
 *   Employee evidence, expiring with the Employee (the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_month_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('month');
            $table->foreignUuid('locked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at', 6)->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'month'], 'staff_attendance_month_locks_one_per_month');
            $table->index(['locked_by_user_id'], 'staff_attendance_month_locks_locked_by_idx');
        });
        DB::statement("ALTER TABLE staff_attendance_month_locks ADD CONSTRAINT staff_attendance_month_locks_month_check CHECK (month = date_trunc('month', month)::date)");

        Schema::create('staff_attendance_locked_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('staff_attendance_record_id');
            $table->uuid('employment_record_id');
            $table->date('month');
            $table->integer('from_version');
            $table->string('before_first_half_status', 8)->nullable();
            $table->string('before_second_half_status', 8)->nullable();
            $table->string('after_first_half_status', 8)->nullable();
            $table->string('after_second_half_status', 8)->nullable();
            $table->string('reason_code', 32);
            $table->uuid('form_key');
            $table->string('status', 10)->default('requested');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at', 6)->useCurrent();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', 6)->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'form_key'], 'staff_attendance_locked_corrections_form_key');
            $table->index(['school_id', 'month', 'status'], 'staff_attendance_locked_corrections_month_idx');
            $table->index(['requested_by_user_id'], 'staff_attendance_locked_corrections_requested_by_idx');
            $table->index(['decided_by_user_id'], 'staff_attendance_locked_corrections_decided_by_idx');
            $table->foreign(['staff_attendance_record_id', 'school_id', 'employment_record_id'], 'staff_attendance_locked_corrections_record_fk')
                ->references(['id', 'school_id', 'employment_record_id'])->on('staff_attendance_records')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX staff_attendance_locked_corrections_one_open ON staff_attendance_locked_corrections (school_id, staff_attendance_record_id) WHERE status = 'requested'");
        DB::statement('ALTER TABLE staff_attendance_locked_corrections ADD CONSTRAINT staff_attendance_locked_corrections_shape_check CHECK ('
            ."from_version >= 1 AND month = date_trunc('month', month)::date "
            ."AND (before_first_half_status IS NULL OR before_first_half_status IN ('present', 'absent')) "
            ."AND (before_second_half_status IS NULL OR before_second_half_status IN ('present', 'absent')) "
            ."AND (after_first_half_status IS NULL OR after_first_half_status IN ('present', 'absent')) "
            ."AND (after_second_half_status IS NULL OR after_second_half_status IN ('present', 'absent')) "
            ."AND reason_code IN ('entered_in_error', 'late_information', 'administrative_review', 'other') "
            .'AND (after_first_half_status IS DISTINCT FROM before_first_half_status OR after_second_half_status IS DISTINCT FROM before_second_half_status) '
            ."AND (after_first_half_status IS NOT NULL OR after_second_half_status IS NOT NULL OR reason_code = 'entered_in_error'))");
        DB::statement("ALTER TABLE staff_attendance_locked_corrections ADD CONSTRAINT staff_attendance_locked_corrections_status_check CHECK (
            (status = 'requested' AND decided_by_user_id IS NULL AND decided_at IS NULL)
            OR (status IN ('approved', 'refused') AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement('ALTER TABLE staff_attendance_locked_corrections ADD CONSTRAINT staff_attendance_locked_corrections_sod_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id)');

        Schema::table('staff_attendance_corrections', function (Blueprint $table) {
            $table->uuid('locked_correction_id')->nullable();
            $table->foreign(['locked_correction_id', 'school_id'], 'staff_attendance_corrections_locked_correction_fk')
                ->references(['id', 'school_id'])->on('staff_attendance_locked_corrections')->restrictOnDelete();
        });
        // One approved request applies once.
        DB::statement('CREATE UNIQUE INDEX staff_attendance_corrections_locked_correction_once ON staff_attendance_corrections (locked_correction_id) WHERE locked_correction_id IS NOT NULL');

        // E21-RH.7 (ADR 0066 §15): the database-recorded anchor and the retention delete guard, as the
        // other Staff Attendance evidence tables have. A decision re-stamps it: the evidence is newer.
        DB::unprepared(<<<'SQL'
            ALTER TABLE staff_attendance_locked_corrections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON staff_attendance_locked_corrections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('decided_at', 'employment_record_id', 'staff_attendance_record_id', 'status');
            CREATE TRIGGER trg_retention_guard_staff_attendance_locked_corrections AFTER DELETE ON staff_attendance_locked_corrections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        foreach (['staff_attendance_month_locks', 'staff_attendance_locked_corrections'] as $table) {
            TenantRls::enable($table);
        }
        TenantRls::makeAppendOnly('staff_attendance_month_locks');
        DB::statement('REVOKE DELETE, TRUNCATE ON staff_attendance_locked_corrections FROM school_os_app');

        DB::unprepared(<<<'SQL'
            -- The one key of a School-month. StaffAttendanceMonthLocks derives the identical string.
            CREATE FUNCTION public.staff_attendance_month_key(p_school uuid, p_month date) RETURNS bigint
                LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, pg_temp AS $$
                SELECT pg_catalog.hashtextextended('hr.staff_month:' || p_school::text || ':' || pg_catalog.to_char(p_month, 'YYYY-MM'), 0)
            $$;

            -- The month check sees a lock committed while it waited only with a fresh snapshot per statement:
            -- READ COMMITTED. A longer-lived snapshot could miss it, so any other level is refused.
            CREATE FUNCTION public.staff_attendance_require_read_committed() RETURNS void
                LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF pg_catalog.current_setting('transaction_isolation') <> 'read committed' THEN
                    RAISE EXCEPTION 'staff_attendance: a month-locked write runs at READ COMMITTED (staff_attendance_isolation)' USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            CREATE FUNCTION public.staff_attendance_month_is_locked(p_school uuid, p_month date) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (SELECT 1 FROM public.staff_attendance_month_locks WHERE school_id = p_school AND month = p_month)
            $$;

            -- A whole-School grant of the capability, an active membership, an enabled User, an active School.
            CREATE FUNCTION public.staff_attendance_holds(p_school uuid, p_user uuid, p_capability text) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (
                    SELECT 1 FROM public.school_memberships m
                      JOIN public.users u ON u.id = m.user_id AND NOT u.is_disabled
                      JOIN public.schools s ON s.id = m.school_id AND s.status = 'active'
                      JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL AND a.campus_id IS NULL
                      JOIN public.role_capabilities rc ON rc.role_id = a.role_id AND rc.capability_key = p_capability
                     WHERE m.school_id = p_school AND m.user_id = p_user AND m.status = 'active')
            $$;

            -- Any link, current or ended, not corrected as erroneous (rule 145): this User is this Employee.
            CREATE FUNCTION public.staff_attendance_is_employee(p_school uuid, p_employee uuid, p_user uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (SELECT 1 FROM public.employee_account_links
                                WHERE school_id = p_school AND employee_id = p_employee AND user_id = p_user AND voided_at IS NULL)
            $$;

            CREATE FUNCTION public.staff_attendance_month_locks_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_today date;
            BEGIN
                PERFORM public.staff_attendance_require_read_committed();
                PERFORM pg_catalog.pg_advisory_xact_lock(public.staff_attendance_month_key(NEW.school_id, NEW.month));
                NEW.locked_at := pg_catalog.clock_timestamp();
                SELECT (pg_catalog.now() AT TIME ZONE timezone)::date INTO v_today FROM public.schools WHERE id = NEW.school_id;
                IF NEW.month >= date_trunc('month', v_today)::date THEN
                    RAISE EXCEPTION 'staff_attendance_month_locks_guard: only a month that has ended can be locked' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT public.staff_attendance_holds(NEW.school_id, NEW.locked_by_user_id, 'hr.staff_attendance.lock') THEN
                    RAISE EXCEPTION 'staff_attendance_month_locks_guard: the locker holds hr.staff_attendance.lock for the whole School' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_month_locks_guard BEFORE INSERT ON staff_attendance_month_locks
                FOR EACH ROW EXECUTE FUNCTION public.staff_attendance_month_locks_guard();

            -- A locked month's record changes only as the application of an approved request.
            CREATE FUNCTION public.staff_attendance_records_month_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_month date := date_trunc('month', NEW.attendance_date)::date;
            BEGIN
                PERFORM public.staff_attendance_require_read_committed();
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, v_month));
                IF NOT public.staff_attendance_month_is_locked(NEW.school_id, v_month) THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION 'staff_attendance_records: the month is locked (staff_attendance_month_locked)' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM public.staff_attendance_corrections c
                                 JOIN public.staff_attendance_locked_corrections r ON r.school_id = c.school_id AND r.id = c.locked_correction_id
                                WHERE c.school_id = NEW.school_id AND c.staff_attendance_record_id = NEW.id
                                  AND c.to_version = NEW.version AND r.status = 'approved') THEN
                    RAISE EXCEPTION 'staff_attendance_records: the month is locked (staff_attendance_month_locked)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_records_month_guard BEFORE INSERT OR UPDATE ON staff_attendance_records
                FOR EACH ROW EXECUTE FUNCTION public.staff_attendance_records_month_guard();

            -- In a locked month a correction row is the application of exactly one approved request.
            CREATE FUNCTION public.staff_attendance_corrections_month_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_month date;
                v_request public.staff_attendance_locked_corrections%ROWTYPE;
            BEGIN
                SELECT date_trunc('month', attendance_date)::date INTO v_month FROM public.staff_attendance_records
                 WHERE school_id = NEW.school_id AND id = NEW.staff_attendance_record_id;
                IF v_month IS NULL THEN
                    RETURN NEW; -- the record's own key refuses it
                END IF;
                PERFORM public.staff_attendance_require_read_committed();
                PERFORM pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, v_month));
                IF NOT public.staff_attendance_month_is_locked(NEW.school_id, v_month) THEN
                    IF NEW.locked_correction_id IS NOT NULL THEN
                        RAISE EXCEPTION 'staff_attendance_corrections: only a locked month takes a locked-month correction' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                SELECT * INTO v_request FROM public.staff_attendance_locked_corrections WHERE school_id = NEW.school_id AND id = NEW.locked_correction_id;
                IF NOT FOUND OR v_request.status <> 'approved'
                   OR v_request.staff_attendance_record_id <> NEW.staff_attendance_record_id
                   OR v_request.employment_record_id <> NEW.employment_record_id
                   OR v_request.from_version <> NEW.from_version
                   OR (v_request.before_first_half_status, v_request.before_second_half_status, v_request.after_first_half_status, v_request.after_second_half_status, v_request.reason_code)
                      IS DISTINCT FROM (NEW.before_first_half_status, NEW.before_second_half_status, NEW.after_first_half_status, NEW.after_second_half_status, NEW.reason_code)
                   OR v_request.decided_by_user_id IS DISTINCT FROM NEW.corrected_by_user_id THEN
                    RAISE EXCEPTION 'staff_attendance_corrections: the month is locked (staff_attendance_month_locked)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_corrections_month_guard BEFORE INSERT ON staff_attendance_corrections
                FOR EACH ROW EXECUTE FUNCTION public.staff_attendance_corrections_month_guard();

            CREATE FUNCTION public.staff_attendance_locked_corrections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_record public.staff_attendance_records%ROWTYPE;
            BEGIN
                -- A request and its decision read the record and the parties' authority: a fresh snapshot each.
                PERFORM public.staff_attendance_require_read_committed();
                SELECT * INTO v_record FROM public.staff_attendance_records
                 WHERE school_id = NEW.school_id AND id = NEW.staff_attendance_record_id;

                IF TG_OP = 'INSERT' THEN
                    NEW.requested_at := pg_catalog.clock_timestamp();
                    IF NEW.status IS DISTINCT FROM 'requested' THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: a request is born requested' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_record.id IS NULL OR v_record.employment_record_id <> NEW.employment_record_id
                       OR NEW.month <> date_trunc('month', v_record.attendance_date)::date THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: a request names a record of its month' USING ERRCODE = 'check_violation';
                    END IF;
                    PERFORM pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, NEW.month));
                    IF NOT public.staff_attendance_month_is_locked(NEW.school_id, NEW.month) THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: only a locked month takes a request' USING ERRCODE = 'check_violation';
                    END IF;
                    IF v_record.version <> NEW.from_version
                       OR (v_record.first_half_status, v_record.second_half_status) IS DISTINCT FROM (NEW.before_first_half_status, NEW.before_second_half_status) THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: a request starts from the record''s current version and values (staff_attendance_correction_stale)' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT public.staff_attendance_holds(NEW.school_id, NEW.requested_by_user_id, 'hr.staff_attendance.manage') THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: the requester holds hr.staff_attendance.manage for the whole School' USING ERRCODE = 'check_violation';
                    END IF;
                    IF public.staff_attendance_is_employee(NEW.school_id, v_record.employee_id, NEW.requested_by_user_id) THEN
                        RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: nobody requests a correction of their own attendance' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status IS DISTINCT FROM 'requested' THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: a decision is final' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.id, NEW.school_id, NEW.staff_attendance_record_id, NEW.employment_record_id, NEW.month, NEW.from_version,
                    NEW.before_first_half_status, NEW.before_second_half_status, NEW.after_first_half_status, NEW.after_second_half_status,
                    NEW.reason_code, NEW.form_key, NEW.requested_by_user_id, NEW.requested_at)
                   IS DISTINCT FROM (OLD.id, OLD.school_id, OLD.staff_attendance_record_id, OLD.employment_record_id, OLD.month, OLD.from_version,
                    OLD.before_first_half_status, OLD.before_second_half_status, OLD.after_first_half_status, OLD.after_second_half_status,
                    OLD.reason_code, OLD.form_key, OLD.requested_by_user_id, OLD.requested_at) THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: the terms of a request are frozen' USING ERRCODE = 'check_violation';
                END IF;
                NEW.decided_at := pg_catalog.clock_timestamp();
                IF NOT public.staff_attendance_holds(NEW.school_id, NEW.decided_by_user_id, 'hr.staff_attendance.lock') THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: the reviewer holds hr.staff_attendance.lock for the whole School' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.decided_by_user_id = NEW.requested_by_user_id
                   OR public.employee_account_link_correction_same_person(NEW.school_id, NEW.requested_by_user_id, NEW.decided_by_user_id)
                   OR public.staff_attendance_is_employee(NEW.school_id, v_record.employee_id, NEW.decided_by_user_id) THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: the reviewer is independent of the requester and of this Employee' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'approved' AND (v_record.version <> NEW.from_version
                   OR (v_record.first_half_status, v_record.second_half_status) IS DISTINCT FROM (NEW.before_first_half_status, NEW.before_second_half_status)) THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_guard: the record changed since the request (staff_attendance_correction_stale)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_locked_corrections_guard BEFORE INSERT OR UPDATE ON staff_attendance_locked_corrections
                FOR EACH ROW EXECUTE FUNCTION public.staff_attendance_locked_corrections_guard();

            -- The only thing that changes a locked month. It cannot run without the approval that fires it,
            -- and it applies the request's own values, so what was approved is exactly what changes.
            CREATE FUNCTION public.staff_attendance_locked_corrections_apply() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                INSERT INTO public.staff_attendance_corrections (id, school_id, staff_attendance_record_id, employment_record_id, from_version, to_version,
                        before_first_half_status, before_second_half_status, after_first_half_status, after_second_half_status,
                        reason_code, corrected_by_user_id, locked_correction_id, created_at)
                VALUES (gen_random_uuid(), NEW.school_id, NEW.staff_attendance_record_id, NEW.employment_record_id, NEW.from_version, NEW.from_version + 1,
                        NEW.before_first_half_status, NEW.before_second_half_status, NEW.after_first_half_status, NEW.after_second_half_status,
                        NEW.reason_code, NEW.decided_by_user_id, NEW.id, NEW.decided_at);
                UPDATE public.staff_attendance_records
                   SET first_half_status = NEW.after_first_half_status, second_half_status = NEW.after_second_half_status,
                       version = NEW.from_version + 1, updated_at = NEW.decided_at
                 WHERE school_id = NEW.school_id AND id = NEW.staff_attendance_record_id AND version = NEW.from_version;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'staff_attendance_locked_corrections_apply: the record changed since the request (staff_attendance_correction_stale)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE TRIGGER trg_staff_attendance_locked_corrections_apply AFTER UPDATE ON staff_attendance_locked_corrections
                FOR EACH ROW WHEN (NEW.status = 'approved' AND OLD.status = 'requested')
                EXECUTE FUNCTION public.staff_attendance_locked_corrections_apply();

            REVOKE ALL ON FUNCTION public.staff_attendance_require_read_committed() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_require_read_committed() TO school_os_app;
            REVOKE ALL ON FUNCTION public.staff_attendance_month_key(uuid, date) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_month_is_locked(uuid, date) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_holds(uuid, uuid, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_is_employee(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_month_locks_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_records_month_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_corrections_month_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_locked_corrections_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.staff_attendance_locked_corrections_apply() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_month_key(uuid, date) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_month_is_locked(uuid, date) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_holds(uuid, uuid, text) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_is_employee(uuid, uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_month_locks_guard() TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_records_month_guard() TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_corrections_month_guard() TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.staff_attendance_locked_corrections_guard() TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP are atomic and see every School.
        DB::statement('ALTER TABLE staff_attendance_month_locks NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE staff_attendance_locked_corrections NO FORCE ROW LEVEL SECURITY');
        if (DB::table('staff_attendance_month_locks')->exists() || DB::table('staff_attendance_locked_corrections')->exists()) {
            throw new RuntimeException('Refusing to roll back: Staff Attendance months are locked or corrections requested (HR.R1-C18). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_staff_attendance_locked_corrections_apply ON staff_attendance_locked_corrections;
            DROP TRIGGER trg_staff_attendance_locked_corrections_guard ON staff_attendance_locked_corrections;
            DROP TRIGGER trg_staff_attendance_corrections_month_guard ON staff_attendance_corrections;
            DROP TRIGGER trg_staff_attendance_records_month_guard ON staff_attendance_records;
            DROP TRIGGER trg_staff_attendance_month_locks_guard ON staff_attendance_month_locks;
            DROP FUNCTION public.staff_attendance_locked_corrections_apply();
            DROP FUNCTION public.staff_attendance_locked_corrections_guard();
            DROP FUNCTION public.staff_attendance_corrections_month_guard();
            DROP FUNCTION public.staff_attendance_records_month_guard();
            DROP FUNCTION public.staff_attendance_month_locks_guard();
            DROP FUNCTION public.staff_attendance_is_employee(uuid, uuid, uuid);
            DROP FUNCTION public.staff_attendance_holds(uuid, uuid, text);
            DROP FUNCTION public.staff_attendance_month_is_locked(uuid, date);
            DROP FUNCTION public.staff_attendance_month_key(uuid, date);
            DROP FUNCTION public.staff_attendance_require_read_committed();
            SQL);
        DB::statement('DROP INDEX staff_attendance_corrections_locked_correction_once');
        Schema::table('staff_attendance_corrections', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_corrections_locked_correction_fk');
            $table->dropColumn('locked_correction_id');
        });
        Schema::dropIfExists('staff_attendance_locked_corrections');
        Schema::dropIfExists('staff_attendance_month_locks');
    }
};
