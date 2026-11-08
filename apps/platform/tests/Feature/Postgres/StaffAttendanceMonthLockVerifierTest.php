<?php

namespace Tests\Feature\Postgres;

use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR.R1-C20 (WO-6, C18-A7): `platform:verify-database` pins the Staff Attendance month-lock backstops,
 * and each pin fails when its property is really broken. Every break is committed through the owner
 * connection on the isolated test database and restored in `finally`, and the check passes again.
 */
class StaffAttendanceMonthLockVerifierTest extends TestCase
{
    private function checkStatus(string $code): string
    {
        return collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')[$code]->status;
    }

    #[Test]
    public function the_verifier_passes_on_the_real_schema(): void
    {
        $this->assertSame(CheckResult::PASS, $this->checkStatus('staff_attendance_month_lock_invariants'));
        $this->assertSame(CheckResult::PASS, $this->checkStatus('runtime_destructive_privileges_restricted'));
    }

    #[Test]
    public function each_backstop_failing_fails_its_check(): void
    {
        $admin = DB::connection('pgsql_admin');
        $regressions = [];
        foreach (DatabaseRoleVerifier::STAFF_ATTENDANCE_MONTH_LOCK_GUARDS as $trigger => [$table]) {
            $regressions[] = ['staff_attendance_month_lock_invariants', "ALTER TABLE {$table} DISABLE TRIGGER {$trigger}", "ALTER TABLE {$table} ENABLE TRIGGER {$trigger}"];
        }
        $regressions[] = ['staff_attendance_month_lock_invariants', 'ALTER TABLE staff_attendance_month_locks NO FORCE ROW LEVEL SECURITY', 'ALTER TABLE staff_attendance_month_locks FORCE ROW LEVEL SECURITY'];
        $regressions[] = ['staff_attendance_month_lock_invariants', 'ALTER TABLE staff_attendance_locked_corrections NO FORCE ROW LEVEL SECURITY', 'ALTER TABLE staff_attendance_locked_corrections FORCE ROW LEVEL SECURITY'];
        $regressions[] = ['staff_attendance_month_lock_invariants', 'GRANT EXECUTE ON FUNCTION staff_attendance_locked_corrections_apply() TO school_os_app', 'REVOKE EXECUTE ON FUNCTION staff_attendance_locked_corrections_apply() FROM school_os_app'];
        $regressions[] = ['runtime_destructive_privileges_restricted', 'GRANT DELETE ON staff_attendance_month_locks TO school_os_app', 'REVOKE DELETE ON staff_attendance_month_locks FROM school_os_app'];
        $regressions[] = ['runtime_destructive_privileges_restricted', 'GRANT UPDATE ON staff_attendance_month_locks TO school_os_app', 'REVOKE UPDATE ON staff_attendance_month_locks FROM school_os_app'];
        $regressions[] = ['runtime_destructive_privileges_restricted', 'GRANT DELETE ON staff_attendance_locked_corrections TO school_os_app', 'REVOKE DELETE ON staff_attendance_locked_corrections FROM school_os_app'];

        // Definitions read from the catalogue, so each break below restores exactly what was there.
        $triggerDef = fn (string $name) => $admin->selectOne('select pg_get_triggerdef(oid) as d from pg_trigger where tgname = ?', [$name])->d;
        $functionDef = fn (string $name) => $admin->selectOne("select pg_get_functiondef(oid) as d from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$name])->d;
        $indexDef = fn (string $name) => $admin->selectOne('select pg_get_indexdef(?::regclass) as d', [$name])->d;
        $records = $triggerDef('trg_staff_attendance_records_month_guard');
        $restoreRecords = "DROP TRIGGER trg_staff_attendance_records_month_guard ON staff_attendance_records; {$records}";
        $regressions[] = ['staff_attendance_month_lock_invariants', 'DROP TRIGGER trg_staff_attendance_records_month_guard ON staff_attendance_records; '.str_replace('EXECUTE FUNCTION staff_attendance_records_month_guard()', 'EXECUTE FUNCTION staff_attendance_corrections_month_guard()', $records), $restoreRecords];
        $regressions[] = ['staff_attendance_month_lock_invariants', 'DROP TRIGGER trg_staff_attendance_records_month_guard ON staff_attendance_records; '.str_replace('BEFORE INSERT OR UPDATE', 'BEFORE INSERT', $records), $restoreRecords];
        $guard = $functionDef('staff_attendance_corrections_month_guard');
        $regressions[] = ['staff_attendance_month_lock_invariants', str_replace('PERFORM public.staff_attendance_require_read_committed();', '-- PERFORM public.staff_attendance_require_read_committed();', $guard), $guard];
        $regressions[] = ['staff_attendance_month_lock_invariants', str_replace('pg_advisory_xact_lock_shared(', 'pg_advisory_xact_lock(', $guard), $guard];
        $helper = $functionDef('staff_attendance_require_read_committed');
        $regressions[] = ['staff_attendance_month_lock_invariants', 'CREATE OR REPLACE FUNCTION public.staff_attendance_require_read_committed() RETURNS void LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $f$ BEGIN END; $f$', $helper];
        $regressions[] = ['staff_attendance_month_lock_invariants', 'ALTER FUNCTION staff_attendance_locked_corrections_apply() SECURITY INVOKER', 'ALTER FUNCTION staff_attendance_locked_corrections_apply() SECURITY DEFINER'];
        $once = $indexDef('staff_attendance_corrections_locked_correction_once');
        $regressions[] = ['staff_attendance_month_lock_invariants', 'DROP INDEX staff_attendance_corrections_locked_correction_once; '.str_replace('WHERE (locked_correction_id IS NOT NULL)', 'WHERE ((locked_correction_id IS NOT NULL) AND false)', $once), "DROP INDEX staff_attendance_corrections_locked_correction_once; {$once}"];

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
