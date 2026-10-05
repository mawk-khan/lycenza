<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\TestCase;

/**
 * HRX.6 (E21-D9, ADR 0065 §27, §27.10 hardening): Leave and Staff
 * Attendance evidence leaves ONLY through the two narrow retention
 * functions, and only for the authorized retention identity (the
 * migration/owner connection). The runtime role has neither DELETE on the
 * tables nor EXECUTE on the functions; the functions themselves refuse any
 * other session user and any School under a retention hold, and re-prove
 * the tenant, the floor, the separation and the row age. No cascade is
 * added, Payroll's HRX snapshot and School configuration are out of reach.
 *
 * COMMITTED fixtures: the retention identity's connection cannot see an
 * open test transaction (PurgesCommittedHrxFixtures cleans up, hermetically).
 */
class HrxRetentionGuardTest extends TestCase
{
    use CreatesHrxRetentionFixtures, PurgesCommittedHrxFixtures;

    private const MIGRATION = 'migrations/2026_11_23_090000_add_hrx_evidence_retention.php';

    private const HARDENING = 'migrations/2026_11_24_090000_harden_hrx_retention_privileges.php';

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8, 'retention.hold_school_ids' => []]);
    }

    protected function tearDown(): void
    {
        config(['retention.hold_school_ids' => []]);
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    /** The database's refusal message for $statement, run as the RUNTIME role in a savepoint ('' when it succeeds). */
    private function refusal(School $school, callable $statement): string
    {
        try {
            $this->inSchool($school, fn () => DB::transaction($statement));

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schools[] = $school;

        return $school;
    }

    /** The SQL calling one purge function directly (destructive unless $dryRun); bindings: School, Employee, cutoff date. */
    private function purgeSql(string $kind, bool $dryRun = false): string
    {
        $function = $kind === 'leave' ? 'retention_expire_leave_employee_evidence' : 'retention_expire_staff_attendance_employee_evidence';

        return "SELECT {$function}(?, ?, ?, ".($dryRun ? 'true' : 'false').') AS n';
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
    public function the_runtime_role_cannot_execute_the_purge_functions_or_the_prologue_even_in_its_own_tenant_context(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        $args = [$w['school']->id, $leaver['employeeId'], '2018-01-01'];

        foreach (['leave', 'staff_attendance'] as $kind) {
            foreach ([false, true] as $dryRun) {
                $this->assertStringContainsString('permission denied for function', $this->refusal($w['school'], fn () => DB::select($this->purgeSql($kind, $dryRun), $args)), "{$kind}: no runtime EXECUTE");
            }
            // Through the PHP gateway on the runtime connection too: the database decides, not the caller.
            $this->assertStringContainsString('permission denied for function', $this->refusal($w['school'], fn () => app(RetentionExpiry::class)->hrxEmployeeEvidence($kind, $w['school'], $leaver['employeeId'], '2018-01-01', false)));
        }
        $this->assertStringContainsString('permission denied for function', $this->refusal($w['school'], fn () => DB::select('SELECT retention_lock_hrx_employee(?, ?, ?)', $args)));
        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']), 'nothing removed');
    }

    #[Test]
    public function even_an_accidental_execute_grant_cannot_make_the_runtime_role_a_retention_identity(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);

        // Defense in depth: simulate a mistaken GRANT, then always restore the closed state.
        $admin->statement('GRANT EXECUTE ON FUNCTION retention_expire_leave_employee_evidence(uuid, uuid, date, boolean) TO school_os_app');
        try {
            $refusal = $this->refusal($w['school'], fn () => DB::select($this->purgeSql('leave'), [$w['school']->id, $leaver['employeeId'], '2018-01-01']));
        } finally {
            $admin->statement('REVOKE EXECUTE ON FUNCTION retention_expire_leave_employee_evidence(uuid, uuid, date, boolean) FROM school_os_app');
        }

        $this->assertStringContainsString('retention_privilege', $refusal, 'the session user is checked, not the caller\'s claim');
        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']));
        $this->assertFalse((bool) DB::selectOne("SELECT has_function_privilege('school_os_app', 'retention_expire_leave_employee_evidence(uuid, uuid, date, boolean)', 'EXECUTE') AS e")->e);
    }

    #[Test]
    public function a_held_school_cannot_be_purged_by_a_direct_authorized_call_until_the_hold_is_explicitly_released(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        $args = [$w['school']->id, $leaver['employeeId'], '2018-01-01'];
        $active = fn (): int => DB::connection(RetentionHolds::MAINTENANCE_CONNECTION)->table('retention_holds')->where('school_id', $w['school']->id)->whereNull('released_at')->count();

        // 1-2. Old enough for D9; the School is held: the transitional configuration, reconciled ADD-ONLY into the authoritative store.
        config(['retention.hold_school_ids' => [$w['school']->id]]);
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('Placed 1 hold(s) from configuration')->assertSuccessful();
        $this->assertSame(1, $active());

        // 3-4. The authorized retention identity calls the primitive directly: refused, nothing removed.
        foreach (['leave', 'staff_attendance'] as $kind) {
            $this->assertStringContainsString('retention_hold', (string) $this->asRetention($w['school'], fn () => DB::select($this->purgeSql($kind), $args)), "{$kind}: the hold is enforced at the destructive boundary");
        }
        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']), 'zero HRX rows removed');
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->expectsOutputToContain('held: 2')->assertSuccessful();
        $this->assertTrue($this->inSchool($w['school'], fn () => DB::table('employees')->where('id', $leaver['employeeId'])->exists()), 'held HRX evidence keeps the Employee');

        // 5. Removing it from configuration releases NOTHING (also not after another reconciliation).
        config(['retention.hold_school_ids' => []]);
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('Placed 0 hold(s) from configuration; released none')->assertSuccessful();
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->expectsOutputToContain('held: 2')->assertSuccessful();
        $this->assertSame(1, $active(), 'configuration removal never releases a database hold');
        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']));

        // 6-7. Only the explicit, audited release does; the scheduled run, as the retention identity, then purges.
        $this->artisan('platform:retention-hold-release', ['--school' => $w['school']->id, '--reason' => 'matter_concluded', '--reference' => 'CHG-1001', '--force' => true])
            ->expectsOutputToContain('Released hold')->assertSuccessful();
        $this->assertSame(0, $active());
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
            ->expectsOutputToContain('Deleted leave evidence of 1 Employee(s) and staff attendance evidence of 1 Employee(s) (dependency-blocked: 0, held: 0, errors: 0)')
            ->assertSuccessful();
        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($w['school'], $leaver['employeeId']));
        $this->assertFalse($this->inSchool($w['school'], fn () => DB::table('employees')->where('id', $leaver['employeeId'])->exists()), 'purged HRX no longer blocks the Employee');
    }

    #[Test]
    public function the_runtime_role_can_neither_read_place_nor_release_a_hold(): void
    {
        $school = $this->school();
        app(RetentionHolds::class)->place($school->id, 'audit', 'CHG-1002');

        $this->assertStringContainsString('permission denied', $this->refusal($school, fn () => DB::table('retention_holds')->where('school_id', $school->id)->delete()));
        $this->assertStringContainsString('permission denied', $this->refusal($school, fn () => DB::table('retention_holds')->where('school_id', $school->id)->update(['release_reason_code' => 'other'])));
        $this->assertStringContainsString('permission denied', $this->refusal($school, fn () => DB::table('retention_holds')->insert(['scope' => 'platform', 'reason_code' => 'other', 'placed_via' => 'operator_command', 'placed_by_login' => 'x', 'placed_at' => now()])));
        $this->assertStringContainsString('permission denied', $this->refusal($school, fn () => DB::table('retention_holds')->count()));
        foreach (["retention_hold_place('platform', null, 'other', null, 'operator_command')", "retention_hold_release('school', '{$school->id}', 'other', null)", 'retention_hold_active_scopes()', "retention_assert_not_held('{$school->id}')"] as $call) {
            $this->assertStringContainsString('permission denied for function', $this->refusal($school, fn () => DB::select("SELECT * FROM {$call}")), $call);
        }
        $this->assertSame(1, DB::connection(RetentionHolds::MAINTENANCE_CONNECTION)->table('retention_holds')->where('school_id', $school->id)->whereNull('released_at')->count());
    }

    #[Test]
    public function the_functions_re_prove_tenant_floor_separation_and_age_even_for_the_retention_identity(): void
    {
        $w = $this->pastWorld();
        ['old' => $old, 'current' => $current, 'early' => $early] = $this->pastLeavers($w, ['old' => '2016-04-30', 'current' => null, 'early' => '2016-01-31']);
        $other = $this->pastWorld();
        $otherLeaver = $this->pastLeaver($other);
        $otherBefore = $this->hrxRows($other['school'], $otherLeaver['employeeId']);
        $cutoff = '2018-01-01';

        foreach (['leave', 'staff_attendance'] as $kind) {
            // Wrong School context, even under the elevated identity: the School must be the current tenant.
            $this->assertStringContainsString('retention_tenant', (string) $this->asRetention($other['school'], fn () => DB::select($this->purgeSql($kind), [$w['school']->id, $old['employeeId'], $cutoff])));
            // School A's context and id with School B's Employee: not proven separated in A, nothing of B removed.
            $this->assertStringContainsString('retention_payroll_employee', (string) $this->asRetention($w['school'], fn () => DB::select($this->purgeSql($kind), [$w['school']->id, $otherLeaver['employeeId'], $cutoff])));
            // A cutoff younger than 8 calendar years is refused, whatever the caller computed.
            $this->assertStringContainsString('retention_floor', (string) $this->asRetention($w['school'], fn () => DB::select($this->purgeSql($kind), [$w['school']->id, $old['employeeId'], now()->subYears(7)->toDateString()])));
            // A current Employee is refused.
            $this->assertStringContainsString('retention_payroll_employee', (string) $this->asRetention($w['school'], fn () => DB::select($this->purgeSql($kind), [$w['school']->id, $current['employeeId'], $cutoff])));
        }
        $this->assertSame($otherBefore, $this->hrxRows($other['school'], $otherLeaver['employeeId']), 'School B is untouched');

        // Separated in January 2016, but the close and the cancellation were written in April: with a
        // March cutoff that Leave unit is younger than the cutoff and kept; its attendance (2015: one record, one correction) is not.
        $this->assertStringContainsString('retention_hrx_dependency', (string) $this->asRetention($w['school'], fn () => DB::select($this->purgeSql('leave', true), [$w['school']->id, $early['employeeId'], '2016-03-01'])));
        $this->assertSame(2, (int) $this->asRetention($w['school'], fn () => DB::selectOne($this->purgeSql('staff_attendance', true), [$w['school']->id, $early['employeeId'], '2016-03-01'])->n));

        // The proper unit is accepted: a dry run counts every row it would remove, and removes none.
        $rows = $this->hrxRows($w['school'], $old['employeeId']);
        $leave = array_sum(array_intersect_key($rows, array_flip(array_filter(self::HRX_EVIDENCE, fn ($t) => str_starts_with($t, 'leave_')))));
        $this->assertSame($leave, (int) $this->asRetention($w['school'], fn () => DB::selectOne($this->purgeSql('leave', true), [$w['school']->id, $old['employeeId'], $cutoff])->n));
        $this->assertSame($rows['staff_attendance_records'] + $rows['staff_attendance_corrections'], (int) $this->asRetention($w['school'], fn () => DB::selectOne($this->purgeSql('staff_attendance', true), [$w['school']->id, $old['employeeId'], $cutoff])->n));
        $this->assertSame($rows, $this->hrxRows($w['school'], $old['employeeId']), 'a dry run deletes nothing');
    }

    #[Test]
    public function the_functions_are_narrow_definers_closed_to_the_runtime_role_and_verified(): void
    {
        $rows = DB::select(
            "SELECT p.proname, p.prosecdef, array_to_string(p.proconfig, ',') AS config, pg_get_userbyid(p.proowner) AS owner,
                    has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime,
                    EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public
               FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace
                AND p.proname IN ('retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence', 'retention_lock_hrx_employee')
              ORDER BY p.proname",
        );
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertFalse((bool) $row->public, "{$row->proname}: never PUBLIC");
            $this->assertFalse((bool) $row->runtime, "{$row->proname}: never the runtime role");
            $this->assertNotSame('school_os_app', $row->owner, "{$row->proname}: not owned by the runtime role");
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $row->config, "{$row->proname}: pinned search_path");
            $this->assertSame($row->proname !== 'retention_lock_hrx_employee', (bool) $row->prosecdef, "{$row->proname}: definer only where it deletes");
        }
        foreach (['retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence'] as $function) {
            $this->assertContains($function, DatabaseRoleVerifier::PRIVILEGED_RETENTION_FUNCTIONS);
        }
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $checks['privileged_retention_functions_closed']->status);
        $this->assertSame(CheckResult::PASS, $checks['retention_functions_narrow']->status);

        $hardening = (string) file_get_contents(database_path(self::HARDENING));
        $this->assertStringContainsString('session_user', $hardening, 'the authorization is the unforgeable session user');
        $this->assertStringNotContainsString('current_setting', $hardening, 'never a client-settable session variable');
        $up = strstr($hardening, 'public function down', true);
        $this->assertStringNotContainsString('GRANT', (string) $up, 'the hardening grants nothing');
        $this->assertStringNotContainsString('DELETE FROM', $hardening, 'and deletes nothing');
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
