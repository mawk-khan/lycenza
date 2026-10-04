<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\TestCase;

/**
 * HRX.6 (E21-D9, ADR 0065 §27): Leave and Staff Attendance evidence leaves
 * ONLY through the two narrow retention functions, which re-prove every
 * unit in the database. The runtime role gains no privilege, no cascade is
 * added, Payroll's HRX snapshot and School configuration are out of reach,
 * and only the employee retention run reaches the mechanism.
 */
class HrxRetentionGuardTest extends TestCase
{
    use CreatesHrxRetentionFixtures;

    private const MIGRATION = 'migrations/2026_11_23_090000_add_hrx_evidence_retention.php';

    /** The database's refusal message for $statement, run as the runtime role in a savepoint ('' when it succeeds). */
    private function refusal(School $school, callable $statement): string
    {
        try {
            $this->inSchool($school, fn () => DB::transaction($statement));

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> app-relative files whose code contains $needle */
    private function filesContaining(string $needle): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        sort($files);

        return array_values(array_map(fn ($f) => substr($f, strlen(app_path()) + 1), array_filter($files, fn ($f) => str_contains($this->code($f), $needle))));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_hrx_evidence_and_append_only_rows_stay_unchangeable(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);

        foreach (self::HRX_EVIDENCE as $table) {
            $this->assertStringContainsString('permission denied', $this->refusal($w['school'], fn () => DB::table($table)->where('school_id', $w['school']->id)->delete()), "{$table}: no runtime DELETE");
            $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', ?, 'DELETE') AS d", [$table])->d, "{$table}: no DELETE grant");
        }
        foreach (['leave_ledger_entries', 'leave_decisions', 'leave_request_days', 'leave_year_close_items', 'leave_year_close_reconciliations', 'staff_attendance_corrections'] as $table) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege('school_os_app', ?, 'UPDATE') AS u", [$table])->u, "{$table}: append-only");
        }
        $this->assertSame(1, $this->hrxRows($w['school'], $leaver['employeeId'])['staff_attendance_records']);
    }

    #[Test]
    public function the_functions_re_prove_tenant_floor_separation_and_age_themselves(): void
    {
        $w = $this->pastWorld();
        ['old' => $old, 'current' => $current, 'early' => $early] = $this->pastLeavers($w, ['old' => '2016-04-30', 'current' => null, 'early' => '2016-01-31']);
        $other = $this->createSchool();
        $expiry = app(RetentionExpiry::class);
        $cutoff = '2018-01-01';

        foreach (['leave', 'staff_attendance'] as $kind) {
            // Wrong School context: the School must be the current tenant.
            $this->assertStringContainsString('retention_tenant', $this->refusal($other, fn () => $expiry->hrxEmployeeEvidence($kind, $w['school'], $old['employeeId'], $cutoff, true)));
            // Another School's Employee under this School's context is not proven separated here.
            $this->assertStringContainsString('retention_payroll_employee', $this->refusal($other, fn () => $expiry->hrxEmployeeEvidence($kind, $other, $old['employeeId'], $cutoff, true)));
            // A cutoff younger than 8 calendar years is refused, whatever the caller computed.
            $this->assertStringContainsString('retention_floor', $this->refusal($w['school'], fn () => $expiry->hrxEmployeeEvidence($kind, $w['school'], $old['employeeId'], now()->subYears(7)->toDateString(), true)));
            // A current Employee is refused.
            $this->assertStringContainsString('retention_payroll_employee', $this->refusal($w['school'], fn () => $expiry->hrxEmployeeEvidence($kind, $w['school'], $current['employeeId'], $cutoff, true)));
        }

        // Separated in January 2016, but the close and the cancellation were written in April: with a
        // March cutoff that Leave unit is younger than the cutoff and kept; its attendance (2015: one record, one correction) is not.
        $this->assertStringContainsString('retention_hrx_dependency', $this->refusal($w['school'], fn () => $expiry->hrxEmployeeEvidence('leave', $w['school'], $early['employeeId'], '2016-03-01', true)));
        $this->assertSame(2, $this->inSchool($w['school'], fn () => $expiry->hrxEmployeeEvidence('staff_attendance', $w['school'], $early['employeeId'], '2016-03-01', true)));

        // The proper unit is accepted: a dry run counts every row it would remove.
        $rows = $this->hrxRows($w['school'], $old['employeeId']);
        $leave = array_sum(array_intersect_key($rows, array_flip(array_filter(self::HRX_EVIDENCE, fn ($t) => str_starts_with($t, 'leave_')))));
        $this->assertSame($leave, $this->inSchool($w['school'], fn () => $expiry->hrxEmployeeEvidence('leave', $w['school'], $old['employeeId'], $cutoff, true)));
        $this->assertSame($rows['staff_attendance_records'] + $rows['staff_attendance_corrections'], $this->inSchool($w['school'], fn () => $expiry->hrxEmployeeEvidence('staff_attendance', $w['school'], $old['employeeId'], $cutoff, true)));
        $this->assertSame($rows, $this->hrxRows($w['school'], $old['employeeId']), 'a dry run deletes nothing');
    }

    #[Test]
    public function the_functions_are_narrow_definers_executable_by_the_runtime_role_only_and_registered(): void
    {
        $rows = DB::select(
            "SELECT p.proname, p.prosecdef, array_to_string(p.proconfig, ',') AS config,
                    has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime,
                    EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public
               FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace
                AND p.proname IN ('retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence', 'retention_lock_hrx_employee')
              ORDER BY p.proname",
        );
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertFalse((bool) $row->public, "{$row->proname}: never PUBLIC");
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $row->config, "{$row->proname}: pinned search_path");
            $expected = $row->proname !== 'retention_lock_hrx_employee';
            $this->assertSame($expected, (bool) $row->prosecdef, "{$row->proname}: definer only where it deletes");
            $this->assertSame($expected, (bool) $row->runtime, "{$row->proname}: the lock helper is not callable by the runtime role");
        }
        foreach (['retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence'] as $function) {
            $this->assertContains($function, DatabaseRoleVerifier::RETENTION_FUNCTIONS);
        }
    }

    #[Test]
    public function no_cascade_privilege_or_out_of_scope_table_is_reachable_through_the_mechanism(): void
    {
        $migration = (string) file_get_contents(database_path(self::MIGRATION));
        $this->assertStringNotContainsString('GRANT DELETE', $migration, 'no runtime privilege is widened');
        $this->assertStringNotContainsString('CASCADE', $migration, 'no cascade is added');
        $this->assertStringNotContainsString('BYPASSRLS', $migration);
        preg_match_all('/DELETE FROM public\.(\w+)/', $migration, $deletes);
        $this->assertEqualsCanonicalizing(
            ['leave_ledger_entries', 'leave_year_close_reconciliations', 'leave_request_days', 'leave_decisions', 'leave_requests', 'leave_year_close_items', 'leave_policy_assignments', 'staff_attendance_corrections', 'staff_attendance_records'],
            array_values(array_unique($deletes[1])),
            'only per-Employee HRX evidence: never configuration, headers, audit, outbox, HR or Payroll rows',
        );

        // Every foreign key out of an HRX evidence table is RESTRICT, except the School's own.
        $cascades = DB::select(
            "SELECT conrelid::regclass::text AS t, conname FROM pg_constraint
              WHERE contype = 'f' AND confdeltype IN ('c', 'n', 'd') AND confrelid <> 'schools'::regclass
                AND conrelid::regclass::text = ANY (?::text[])",
            ['{'.implode(',', self::HRX_EVIDENCE).'}'],
        );
        $this->assertSame([], $cascades, 'Employee deletion never cascades through HRX');
        $this->assertSame(['c'], array_values(array_unique(array_column(DB::select(
            "SELECT confdeltype FROM pg_constraint WHERE contype = 'f' AND conrelid = 'payroll_run_hrx_inputs'::regclass AND confrelid = 'payroll_run_results'::regclass",
        ), 'confdeltype'))), 'the Payroll snapshot leaves only with its payroll result');
        $this->assertSame([], DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = 'payroll_run_hrx_inputs'::regclass AND confrelid::regclass::text ~ '^(leave_|staff_attendance)'",
        ), 'the Payroll snapshot references no HRX row');
    }

    #[Test]
    public function only_the_hrx_retention_services_reach_the_functions_and_only_the_employee_run_reaches_the_services(): void
    {
        $this->assertSame([
            'Domain/Leave/Application/Retention/LeaveEvidenceRetentionService.php',
            'Domain/StaffAttendance/Application/Retention/StaffAttendanceEvidenceRetentionService.php',
            'Support/Retention/RetentionExpiry.php',
        ], $this->filesContaining('hrxEmployeeEvidence('));
        foreach (['LeaveEvidenceRetentionService', 'StaffAttendanceEvidenceRetentionService'] as $service) {
            $this->assertSame([
                'Console/Commands/PruneEmployeeRecords.php',
                ...array_values(array_filter([str_starts_with($service, 'Leave') ? 'Domain/Leave/Application/Retention/LeaveEvidenceRetentionService.php' : null])),
                ...array_values(array_filter([str_starts_with($service, 'Staff') ? 'Domain/StaffAttendance/Application/Retention/StaffAttendanceEvidenceRetentionService.php' : null])),
                'Support/Retention/Erasure/Subjects/EmployeeErasureAdapter.php',
            ], $this->filesContaining($service));
        }

        $command = (string) file_get_contents(app_path('Console/Commands/PruneEmployeeRecords.php'));
        $this->assertStringContainsString("config('retention.employee_evidence_years')", $command, 'one D9 setting: never a second HRX period');
        $this->assertStringNotContainsString('--employee', $command, 'no force-delete of a named Employee');
        // HRX never adds a retention setting of its own.
        $this->assertStringNotContainsString('leave', strtolower(implode(',', array_keys((array) config('retention')))));
        $this->assertStringNotContainsString('attendance', strtolower(implode(',', array_keys((array) config('retention')))));
    }
}
