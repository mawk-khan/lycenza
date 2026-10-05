<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\TestCase;

/**
 * E21-RH.2 (ADR 0066 §3, §5): the dedicated retention identity
 * `school_os_retention` is narrow, executes exactly the approved HRX pair,
 * and is the ONLY identity the scheduled HRX retention unit uses -- never
 * the migration/owner connection, never the runtime role, and with no
 * fallback when its credential is missing or wrong.
 *
 * COMMITTED fixtures where the retention connection must see rows
 * (PurgesCommittedHrxFixtures cleans up, hermetically).
 */
class RetentionIdentityTest extends TestCase
{
    use CreatesHrxRetentionFixtures, PurgesCommittedHrxFixtures;

    private const ROLE = 'school_os_retention';

    private const HRX = ['retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence'];

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
        DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    /** The message of the exception $statement raises ('' when it succeeds). */
    private function refusal(callable $statement): string
    {
        try {
            $statement();

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    /** @return list<string> connection names that ran a query while $run executed */
    private function connectionsUsedBy(callable $run): array
    {
        $used = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$used): void {
            $used[] = $e->connectionName.(str_contains($e->sql, 'retention_expire_') ? ':purge' : '');
        });
        $run();

        return array_values(array_unique($used));
    }

    #[Test]
    public function the_retention_role_is_narrow_shares_no_role_and_owns_nothing(): void
    {
        $role = DB::selectOne('select oid, rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [self::ROLE]);
        $this->assertNotNull($role, 'provisioned in this environment');
        $this->assertSame([true, false, false, false, false, false, false], [(bool) $role->rolcanlogin, (bool) $role->rolsuper, (bool) $role->rolbypassrls, (bool) $role->rolcreatedb, (bool) $role->rolcreaterole, (bool) $role->rolinherit, (bool) $role->rolreplication]);

        $this->assertSame(0, (int) DB::selectOne('select count(*) as n from pg_auth_members where member = ? or roleid = ?', [$role->oid, $role->oid])->n, 'no membership either way');
        $owner = DB::selectOne("select relowner::regrole::text as o from pg_class where oid = 'public.employees'::regclass")->o;
        foreach ([[self::ROLE, $owner], [self::ROLE, 'school_os_app'], ['school_os_app', self::ROLE]] as [$member, $of]) {
            $this->assertFalse((bool) DB::selectOne("select pg_has_role(?, ?, 'MEMBER') as m", [$member, $of])->m, "{$member} cannot become {$of}");
        }
        $this->assertSame(0, (int) DB::selectOne('select (select count(*) from pg_class where relowner = ?) + (select count(*) from pg_proc where proowner = ?) + (select count(*) from pg_namespace where nspowner = ?) as n', [$role->oid, $role->oid, $role->oid])->n, 'owns no table, function or schema');
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_default_acl where defaclacl::text like '%school_os_retention=%'")->n, 'no default privileges');

        // The runtime role cannot assume it.
        $this->assertStringContainsString('permission denied to set role', $this->refusal(fn () => DB::transaction(fn () => DB::statement('SET LOCAL ROLE '.self::ROLE))));
    }

    #[Test]
    public function the_retention_role_reads_only_the_demonstrated_columns_and_writes_nothing(): void
    {
        $oid = DB::selectOne('select oid from pg_roles where rolname = ?', [self::ROLE])->oid;
        $tableGrants = DB::select('select c.relname, a.privilege_type from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? order by 1, 2', [$oid]);
        $this->assertSame([], $tableGrants, 'no table-level grant at all (only column-level SELECTs)');

        $columns = collect(DB::select('select c.relname, t.attname, a.privilege_type from pg_attribute t join pg_class c on c.oid = t.attrelid cross join lateral aclexplode(t.attacl) a where a.grantee = ? order by 1, 2', [$oid]))
            ->groupBy('relname')->map(fn ($rows) => $rows->pluck('attname')->sort()->values()->all())->all();
        $this->assertSame([
            'employees' => ['id', 'school_id'],
            'employment_records' => ['employee_id', 'ends_on', 'id', 'status'],
            'leave_ledger_entries' => ['employment_record_id'],
            'leave_policy_assignments' => ['employment_record_id'],
            'leave_requests' => ['employee_id'],
            'leave_year_close_items' => ['employment_record_id'],
            'staff_attendance_records' => ['employee_id'],
        ], $columns);
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_attribute t cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type <> 'SELECT'", [$oid])->n);

        foreach (['employees', 'employment_records', 'leave_requests', 'staff_attendance_records', 'payroll_run_results', 'school_audit_events'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse((bool) DB::selectOne('select has_table_privilege(?, ?, ?) as p', [self::ROLE, $table, $privilege])->p, "{$table}: no {$privilege}");
            }
        }
    }

    #[Test]
    public function only_the_retention_login_executes_the_hrx_pair_and_it_executes_no_legacy_function(): void
    {
        foreach (self::HRX as $function) {
            $acl = DB::selectOne("select pg_get_userbyid(p.proowner) as owner, p.prosecdef,
                        has_function_privilege('school_os_app', p.oid, 'EXECUTE') as runtime, has_function_privilege(?, p.oid, 'EXECUTE') as retention,
                        exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0) as public
                   from pg_proc p where p.proname = ?", [self::ROLE, $function]);
            $this->assertSame([false, true, false, true], [(bool) $acl->runtime, (bool) $acl->retention, (bool) $acl->public, (bool) $acl->prosecdef], $function);
            $this->assertNotContains($acl->owner, ['school_os_app', self::ROLE], "{$function}: owned by the controlled owner");
        }
        foreach (DatabaseRoleVerifier::RETENTION_FUNCTIONS as $legacy) {
            $this->assertFalse((bool) DB::selectOne("select bool_or(has_function_privilege(?, p.oid, 'EXECUTE')) as e from pg_proc p where p.proname = ?", [self::ROLE, $legacy])->e, "{$legacy}: not the retention identity's");
        }
        $this->assertFalse((bool) DB::selectOne("select has_function_privilege(?, 'retention_lock_hrx_employee(uuid, uuid, date)', 'EXECUTE') as e", [self::ROLE])->e, 'the prologue is reached only through the pair');
    }

    #[Test]
    public function the_retention_connection_is_the_retention_login_and_the_definer_runs_as_the_owner(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $retention = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION);

        $identity = $retention->selectOne('select session_user::text as s, current_user::text as c');
        $this->assertSame([self::ROLE, self::ROLE], [$identity->s, $identity->c]);
        $owner = DB::selectOne("select pg_get_userbyid(proowner) as o, prosecdef from pg_proc where proname = 'retention_expire_leave_employee_evidence'");
        $this->assertTrue((bool) $owner->prosecdef, 'inside the call current_user is the definer');

        // The body is reachable as the retention login (a dry run counts the unit) ...
        $count = (int) $this->asRetention($w['school'], fn () => DB::selectOne('select retention_expire_leave_employee_evidence(?, ?, ?, true) as n', [$w['school']->id, $leaver['employeeId'], '2018-01-01'])->n);
        $this->assertGreaterThan(0, $count);
        // ... but a legacy destructive function is not.
        // (E21-RH.4 moved the standalone functions to it; a coupled RH.5/RH.6 one stays out of reach.)
        $this->assertStringContainsString('permission denied for function retention_expire_finance_unit', (string) $this->asRetention($w['school'], fn () => DB::select("select retention_expire_finance_unit(?, '{}', '{}', ?, true)", [$w['school']->id, (string) Str::uuid7()])));
        $this->assertStringContainsString('permission denied for function retention_expire_payroll_employee_evidence', (string) $this->asRetention($w['school'], fn () => DB::select('select retention_expire_payroll_employee_evidence(?, ?, ?, true)', [$w['school']->id, $leaver['employeeId'], '2018-01-01'])));

        // The owner / migration login is refused by the exact identity check (no fallback identity).
        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $refusal = $this->refusal(fn () => $admin->transaction(function () use ($admin, $w, $leaver) {
            $admin->statement("select set_config('app.current_school_id', ?, true)", [$w['school']->id]);
            $admin->select('select retention_expire_leave_employee_evidence(?, ?, ?, true)', [$w['school']->id, $leaver['employeeId'], '2018-01-01']);
        }));
        $this->assertStringContainsString('retention_privilege', $refusal, 'even the owner/superuser login is not the retention identity');
    }

    #[Test]
    public function the_scheduled_hrx_unit_runs_only_on_the_retention_connection_never_the_migration_one(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);

        $used = $this->connectionsUsedBy(fn () => $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
            ->expectsOutputToContain('Deleted leave evidence of 1 Employee(s) and staff attendance evidence of 1 Employee(s) (dependency-blocked: 0, held: 0, errors: 0)')
            ->assertSuccessful());

        $this->assertContains(RetentionExpiry::PRIVILEGED_CONNECTION.':purge', $used, 'the purge functions ran on pgsql_retention');
        $this->assertNotContains('pgsql:purge', $used, 'never through the runtime role');
        $this->assertSame([], array_values(array_filter($used, fn ($c) => str_starts_with($c, 'pgsql_admin'))), 'the scheduled run never selects the migration connection');
        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), $this->hrxRows($w['school'], $leaver['employeeId']));
    }

    #[Test]
    public function a_missing_or_wrong_retention_credential_refuses_safely_with_no_fallback(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        $retention = 'database.connections.'.RetentionExpiry::PRIVILEGED_CONNECTION;
        $good = [config("{$retention}.username"), config("{$retention}.password")];

        $cases = [
            'unset' => [null, null],
            'the migration/owner login' => [config('database.connections.pgsql_admin.username'), config('database.connections.pgsql_admin.password')],
            'the runtime login' => [config('database.connections.pgsql.username'), config('database.connections.pgsql.password')],
            'a wrong password' => [self::ROLE, 'not-the-password'],
        ];
        try {
            foreach ($cases as $case => [$username, $password]) {
                config(["{$retention}.username" => $username, "{$retention}.password" => $password]);
                DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);

                // One refused unit per School and participant (the run walks every School, committed residue included).
                $errors = 2 * School::query()->count();
                $used = $this->connectionsUsedBy(fn () => $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
                    ->expectsOutputToContain("Deleted leave evidence of 0 Employee(s) and staff attendance evidence of 0 Employee(s) (dependency-blocked: 0, held: 0, errors: {$errors})")
                    ->assertSuccessful());

                $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']), "{$case}: nothing deleted");
                $this->assertSame([], array_values(array_filter($used, fn ($c) => str_starts_with($c, 'pgsql_admin') || str_ends_with($c, ':purge'))), "{$case}: no purge, no migration-connection fallback");
            }
        } finally {
            config(["{$retention}.username" => $good[0], "{$retention}.password" => $good[1]]);
            DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        }
    }

    #[Test]
    public function the_verifier_proves_the_identity_and_detects_regressions(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['retention_role_narrow', 'retention_role_read_only', 'retention_role_functions_exact', 'retention_connection_identity', 'privileged_retention_functions_closed', 'retention_functions_narrow'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $regressions = [
            // A function outside the approved set (RH.6's Finance unit) -- never one RH.4 legitimately granted.
            'retention_role_functions_exact' => ['GRANT EXECUTE ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) TO school_os_retention', 'REVOKE EXECUTE ON FUNCTION retention_expire_finance_unit(uuid, uuid[], uuid[], uuid, boolean) FROM school_os_retention'],
            'retention_role_read_only' => ['GRANT DELETE ON leave_requests TO school_os_retention', 'REVOKE DELETE ON leave_requests FROM school_os_retention'],
            'privileged_retention_functions_closed' => ['GRANT EXECUTE ON FUNCTION retention_expire_leave_employee_evidence(uuid, uuid, date, boolean) TO school_os_app', 'REVOKE EXECUTE ON FUNCTION retention_expire_leave_employee_evidence(uuid, uuid, date, boolean) FROM school_os_app'],
            'retention_role_narrow' => ['ALTER ROLE school_os_retention BYPASSRLS', 'ALTER ROLE school_os_retention NOBYPASSRLS'],
        ];
        foreach ($regressions as $code => [$break, $restore]) {
            $admin->statement($break);
            try {
                $this->assertSame(CheckResult::FAIL, $checks()[$code]->status, "{$code} detects: {$break}");
            } finally {
                $admin->statement($restore);
            }
        }
        $this->assertSame(CheckResult::PASS, $checks()['retention_role_narrow']->status, 'restored');
    }
}
