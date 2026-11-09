<?php

namespace Tests\Feature\Postgres;

use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR.R1-C22 (WO-5, D-6, S-16): `platform:verify-database` pins the staff calendar's history backstops, and each pin
 * fails when its property is really broken. Every break is committed through the owner connection on the isolated
 * test database, restored from the catalogue in `finally`, and the check passes again.
 */
class StaffCalendarVerifierTest extends TestCase
{
    private function checkStatus(string $code): string
    {
        return collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')[$code]->status;
    }

    #[Test]
    public function the_verifier_passes_on_the_real_schema(): void
    {
        $this->assertSame(CheckResult::PASS, $this->checkStatus('staff_calendar_history_invariants'));
        $this->assertSame(CheckResult::PASS, $this->checkStatus('runtime_destructive_privileges_restricted'));
    }

    #[Test]
    public function each_backstop_failing_fails_its_check(): void
    {
        $admin = DB::connection('pgsql_admin');
        $triggerDef = fn (string $name) => $admin->selectOne('select pg_get_triggerdef(oid) as d from pg_trigger where tgname = ?', [$name])->d;
        $functionDef = fn (string $name) => $admin->selectOne("select pg_get_functiondef(oid) as d from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$name])->d;
        $check = 'staff_calendar_history_invariants';
        $regressions = [];
        foreach ([['staff_working_week_versions', 'trg_staff_working_week_versions_guard'], ['staff_holidays', 'trg_staff_holidays_history_guard'],
            ['staff_working_week_versions', 'trg_staff_attendance_calendar_month_guard'], ['staff_holidays', 'trg_staff_attendance_holiday_month_guard']] as [$table, $trigger]) {
            $regressions[] = [$check, "ALTER TABLE {$table} DISABLE TRIGGER {$trigger}", "ALTER TABLE {$table} ENABLE TRIGGER {$trigger}"];
        }
        $week = $triggerDef('trg_staff_working_week_versions_guard');
        $regressions[] = [$check, 'DROP TRIGGER trg_staff_working_week_versions_guard ON staff_working_week_versions; '.str_replace('BEFORE INSERT OR UPDATE', 'BEFORE INSERT', $week),
            "DROP TRIGGER trg_staff_working_week_versions_guard ON staff_working_week_versions; {$week}"];
        $guard = $functionDef('staff_holidays_history_guard');
        $regressions[] = [$check, str_replace('PERFORM public.staff_calendar_require_read_committed();', '-- PERFORM public.staff_calendar_require_read_committed();', $guard), $guard];
        $month = $functionDef('staff_attendance_calendar_month_guard');
        $regressions[] = [$check, str_replace('pg_advisory_xact_lock_shared(', 'pg_advisory_xact_lock(', $month), $month];
        $start = $functionDef('staff_calendar_history_start');
        $regressions[] = [$check, 'CREATE OR REPLACE FUNCTION public.staff_calendar_history_start(p_school uuid) RETURNS date LANGUAGE sql STABLE SET search_path = pg_catalog, public, pg_temp AS $f$ SELECT NULL::date $f$', $start];
        // C22 review L-3: logic changes that keep every fragment the needles look for.
        $weekGuard = $functionDef('staff_working_week_versions_guard');
        $regressions[] = [$check, str_replace('pg_catalog.pg_has_role(current_user,', 'true OR pg_catalog.pg_has_role(current_user,', $weekGuard), $weekGuard];
        $regressions[] = [$check, (string) preg_replace('/\bBEGIN\b/', 'BEGIN RETURN NEW;', $month, 1), $month];
        $regressions[] = [$check, str_replace('NEW.holiday_on < v_today AND (v_start IS NULL', 'NEW.holiday_on < v_today - 36500 AND (v_start IS NULL', $guard), $guard];
        $recorder = $admin->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'staff_working_week_versions_recorder_check'")->d;
        $regressions[] = [$check, 'ALTER TABLE staff_working_week_versions DROP CONSTRAINT staff_working_week_versions_recorder_check; ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_recorder_check CHECK (true)',
            "ALTER TABLE staff_working_week_versions DROP CONSTRAINT staff_working_week_versions_recorder_check; ALTER TABLE staff_working_week_versions ADD CONSTRAINT staff_working_week_versions_recorder_check {$recorder}"];
        $regressions[] = [$check, 'ALTER TABLE staff_holidays NO FORCE ROW LEVEL SECURITY', 'ALTER TABLE staff_holidays FORCE ROW LEVEL SECURITY'];
        $index = $admin->selectOne("select pg_get_indexdef('staff_holidays_one_active'::regclass) as d")->d;
        $regressions[] = [$check, 'DROP INDEX staff_holidays_one_active; '.str_replace("WHERE ((status)::text = 'active'::text)", 'WHERE false', $index), "DROP INDEX staff_holidays_one_active; {$index}"];
        $regressions[] = [$check, 'GRANT INSERT ON staff_working_weekdays TO school_os_app', 'REVOKE INSERT ON staff_working_weekdays FROM school_os_app'];
        $regressions[] = ['runtime_destructive_privileges_restricted', 'GRANT DELETE ON staff_holidays TO school_os_app', 'REVOKE DELETE ON staff_holidays FROM school_os_app'];
        $regressions[] = ['runtime_destructive_privileges_restricted', 'GRANT UPDATE ON staff_working_weekdays TO school_os_app', 'REVOKE UPDATE ON staff_working_weekdays FROM school_os_app'];

        foreach ($regressions as [$code, $break, $restore]) {
            $admin->unprepared($break);
            try {
                $this->assertSame(CheckResult::FAIL, $this->checkStatus($code), "{$code} detects: {$break}");
            } finally {
                $admin->unprepared($restore);
            }
            $this->assertSame(CheckResult::PASS, $this->checkStatus($code), "{$code} after: {$restore}");
        }
    }
}
