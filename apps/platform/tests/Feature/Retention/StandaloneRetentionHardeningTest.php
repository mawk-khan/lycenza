<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21-RH.4 (ADR 0066 §12): the eleven standalone legacy retention functions
 * run only as the dedicated retention identity, refuse any other session
 * user themselves, and -- destructively -- refuse an active platform hold
 * and, School-scoped, their School's hold, inside PostgreSQL. Every
 * existing floor, predicate and dry-run semantic is unchanged; the eight
 * coupled RH.5/RH.6 functions are untouched.
 *
 * COMMITTED fixtures (the retention connection is its own session).
 */
class StandaloneRetentionHardeningTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    /** @var array<string, string> School-scoped function => its arguments after the School (destructive unless the last is true) */
    private const SCHOOL = [
        'retention_expire_school_audit_events' => 'ts',
        'retention_expire_membership_role_assignments' => 'ts',
        'retention_expire_teaching_assignments' => 'date',
        'retention_expire_school_elevations' => 'ts',
        'retention_expire_communication_delivery_policy_decisions' => 'ts',
        'retention_expire_api_client_credentials' => 'ts',
    ];

    private const PLATFORM = [
        'retention_expire_platform_audit_events', 'retention_expire_released_email_suppressions',
        'retention_expire_group_role_assignments', 'retention_expire_platform_role_assignments', 'retention_expire_erasure_cases',
    ];

    private const COUPLED = [
        'retention_expire_finance_unit', 'retention_expire_student_processing_authorizations', 'retention_expire_student_consent_events',
        'retention_expire_guardian_consent_events', 'retention_expire_learning_content', 'retention_expire_assignment',
        'retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.audit_years' => 7, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
    }

    /** $statement as the retention identity: its result, or the refusal message. */
    private function asRetention(?School $school, callable $statement): mixed
    {
        try {
            return DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $school === null
                ? DB::transaction($statement)
                : app(TenantContext::class)->withSchool($school, fn () => DB::transaction($statement)));
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    private function schoolCall(string $function, School $school, bool $dryRun): mixed
    {
        $cutoff = self::SCHOOL[$function] === 'date' ? now()->subYears(10)->toDateString() : now()->subYears(10)->format('Y-m-d H:i:s');

        return $this->asRetention($school, fn () => DB::selectOne("SELECT {$function}(?, ?, 10, ?) AS n", [$school->id, $cutoff, $dryRun ? 'true' : 'false'])->n);
    }

    private function platformCall(string $function, bool $dryRun): mixed
    {
        return $this->asRetention(null, fn () => DB::selectOne("SELECT {$function}(?, 10, ?) AS n", [now()->subYears(10)->format('Y-m-d H:i:s'), $dryRun ? 'true' : 'false'])->n);
    }

    private function schoolAudit(School $school, string $occurredAt): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => $id, 'school_id' => $school->id, 'occurred_at' => $occurredAt, 'event_type' => 'test.event', 'metadata' => '{}', 'created_at' => $occurredAt,
        ]));

        return $id;
    }

    private function auditExists(School $school, string $id): bool
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('id', $id)->exists());
    }

    #[Test]
    public function the_eleven_move_to_the_retention_identity_and_the_coupled_eight_stay_exactly_where_they_were(): void
    {
        $acl = fn (string $f) => DB::selectOne("SELECT pg_get_userbyid(p.proowner) AS owner, p.prosecdef, array_to_string(p.proconfig, ',') AS cfg,
                has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime, has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS retention,
                EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0) AS public, p.prosrc AS src
           FROM pg_proc p WHERE p.proname = ?", [$f]);

        foreach ([...array_keys(self::SCHOOL), ...self::PLATFORM] as $function) {
            $f = $acl($function);
            $this->assertSame([false, true, false, true], [(bool) $f->runtime, (bool) $f->retention, (bool) $f->public, (bool) $f->prosecdef], $function);
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', $f->cfg, $function);
            $this->assertNotContains($f->owner, ['school_os_app', 'school_os_retention'], $function);
            $this->assertStringContainsString('PERFORM public.retention_assert_retention_identity();', $f->src, $function);
            $scope = array_key_exists($function, self::SCHOOL) ? 'p_school_id' : 'NULL';
            $this->assertStringContainsString("IF NOT p_dry_run THEN\n        PERFORM public.retention_assert_not_held({$scope});", $f->src, $function);
        }
        foreach (self::COUPLED as $function) {
            $f = $acl($function);
            $this->assertSame([true, false], [(bool) $f->runtime, (bool) $f->retention], "{$function}: RH.5/RH.6, unchanged");
            $this->assertStringNotContainsString('retention_assert_retention_identity', $f->src, $function);
        }
        $this->assertSame(count(DatabaseRoleVerifier::STANDALONE_RETENTION_FUNCTIONS), count(self::SCHOOL) + count(self::PLATFORM));
        $this->assertEqualsCanonicalizing(self::COUPLED, DatabaseRoleVerifier::RETENTION_FUNCTIONS);
    }

    #[Test]
    public function only_the_retention_login_reaches_the_bodies_even_the_owner_is_refused(): void
    {
        $school = $this->createSchool();
        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);

        foreach (array_keys(self::SCHOOL) as $function) {
            $this->assertIsNumeric($this->schoolCall($function, $school, true), "{$function}: reachable as the retention login");
            $refusal = '';
            try {
                $admin->transaction(function () use ($admin, $function, $school) {
                    $admin->statement("SELECT set_config('app.current_school_id', ?, true)", [$school->id]);
                    $admin->select("SELECT {$function}(?, ?, 10, true)", [$school->id, self::SCHOOL[$function] === 'date' ? '2010-01-01' : '2010-01-01 00:00:00']);
                });
            } catch (QueryException $e) {
                $refusal = $e->getMessage();
            }
            $this->assertStringContainsString('retention_privilege', $refusal, "{$function}: the owner login is not the retention identity");
        }
        foreach (self::PLATFORM as $function) {
            $this->assertIsNumeric($this->platformCall($function, true), $function);
            $this->assertStringContainsString('retention_privilege', (string) (function () use ($admin, $function) {
                try {
                    $admin->select("SELECT {$function}('2010-01-01 00:00:00', 10, true)");
                } catch (QueryException $e) {
                    return $e->getMessage();
                }

                return '';
            })(), $function);
        }
        // A coupled function stays out of the retention login's reach.
        $this->assertStringContainsString('permission denied for function retention_expire_payroll_run', (string) $this->asRetention($school, fn () => DB::select('SELECT retention_expire_payroll_run(?, ?, ?, true)', [$school->id, (string) Str::uuid7(), '2010-01-01 00:00:00'])));
    }

    #[Test]
    public function a_school_hold_blocks_only_its_school_and_a_platform_hold_blocks_every_function(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $holds = app(RetentionHolds::class);
        $holds->place($a->id, 'litigation', 'CASE-41');

        foreach (array_keys(self::SCHOOL) as $function) {
            $this->assertStringContainsString('retention_hold', (string) $this->schoolCall($function, $a, false), "{$function}: School A held");
            $this->assertIsNumeric($this->schoolCall($function, $a, true), "{$function}: a dry run still counts under a hold");
            $this->assertIsNumeric($this->schoolCall($function, $b, false), "{$function}: School B is independent");
        }
        foreach (self::PLATFORM as $function) {
            $this->assertIsNumeric($this->platformCall($function, false), "{$function}: a School hold does not hold platform records");
        }

        $holds->place(null, 'regulatory_inquiry', 'REG-41');
        foreach (array_keys(self::SCHOOL) as $function) {
            $this->assertStringContainsString('retention_hold', (string) $this->schoolCall($function, $b, false), "{$function}: the platform hold blocks School B too");
        }
        foreach (self::PLATFORM as $function) {
            $this->assertStringContainsString('retention_hold', (string) $this->platformCall($function, false), "{$function}: the platform hold blocks it");
            $this->assertIsNumeric($this->platformCall($function, true), "{$function}: a dry run still counts");
        }

        $holds->release(null, 'inquiry_closed', 'REG-41');
        $holds->release($a->id, 'matter_concluded', 'CASE-41');
        foreach (array_keys(self::SCHOOL) as $function) {
            $this->assertIsNumeric($this->schoolCall($function, $a, false), "{$function}: released");
        }
        foreach (self::PLATFORM as $function) {
            $this->assertIsNumeric($this->platformCall($function, false), "{$function}: released");
        }
    }

    #[Test]
    public function an_eligible_row_is_purged_only_without_a_hold_and_only_past_its_floor(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $oldA = $this->schoolAudit($a, '2012-01-01 00:00:00');
        $youngA = $this->schoolAudit($a, now()->subYears(2)->format('Y-m-d H:i:s'));
        $oldB = $this->schoolAudit($b, '2012-01-01 00:00:00');
        $holds = app(RetentionHolds::class);
        $holds->place($a->id, 'audit', 'CASE-42');

        $this->assertSame(1, (int) $this->schoolCall('retention_expire_school_audit_events', $b, false), 'School B: the old row goes');
        $this->assertStringContainsString('retention_hold', (string) $this->schoolCall('retention_expire_school_audit_events', $a, false));
        $this->assertTrue($this->auditExists($a, $oldA), 'held School A keeps its old row');
        $this->assertFalse($this->auditExists($b, $oldB));

        $holds->release($a->id, 'audit_closed', 'CASE-42');
        $this->assertSame(1, (int) $this->schoolCall('retention_expire_school_audit_events', $a, false));
        $this->assertFalse($this->auditExists($a, $oldA));
        $this->assertTrue($this->auditExists($a, $youngA), 'the 7-year floor still keeps a young row');
        // The floor itself is unchanged: a cutoff younger than 7 years is refused, as the retention identity too.
        $this->assertStringContainsString('retention_floor', (string) $this->asRetention($a, fn () => DB::select('SELECT retention_expire_school_audit_events(?, ?, 10, true)', [$a->id, now()->subYears(6)->format('Y-m-d H:i:s')])));
    }

    #[Test]
    public function a_school_less_row_is_purged_only_without_a_platform_hold_and_a_school_hold_does_not_hold_it(): void
    {
        $school = $this->createSchool();
        $old = (string) Str::uuid7();
        $young = (string) Str::uuid7();
        foreach ([$old => '2012-01-01 00:00:00', $young => now()->subYears(2)->format('Y-m-d H:i:s')] as $id => $at) {
            DB::table('platform_audit_events')->insert(['id' => $id, 'occurred_at' => $at, 'event_type' => 'test.platform', 'metadata' => '{}', 'created_at' => $at]);
        }
        $exists = fn (string $id): bool => DB::table('platform_audit_events')->where('id', $id)->exists();
        $holds = app(RetentionHolds::class);

        $holds->place(null, 'regulatory_inquiry', 'REG-45');
        $holds->place($school->id, 'litigation', 'CASE-45');
        $this->assertStringContainsString('retention_hold', (string) $this->platformCall('retention_expire_platform_audit_events', false));
        $this->assertTrue($exists($old), 'held by the platform hold');

        $holds->release(null, 'inquiry_closed', 'REG-45');
        $this->assertSame(1, (int) $this->platformCall('retention_expire_platform_audit_events', false), 'released: the School hold alone does not hold School-less rows');
        $this->assertFalse($exists($old));
        $this->assertTrue($exists($young), 'the 7-year floor still keeps a young row');
        $this->assertStringContainsString('retention_floor', (string) $this->asRetention(null, fn () => DB::select('SELECT retention_expire_platform_audit_events(?, 10, true)', [now()->subYears(6)->format('Y-m-d H:i:s')])));
        $holds->release($school->id, 'matter_concluded', 'CASE-45');
    }

    #[Test]
    public function the_php_path_runs_on_the_retention_connection_treats_a_database_hold_as_held_and_never_falls_back(): void
    {
        $school = $this->createSchool();
        $old = $this->schoolAudit($school, '2012-01-01 00:00:00');
        $expiry = app(RetentionExpiry::class);
        $cutoff = now()->subYears(7);

        // A hold that exists only in the database (no configuration): the PHP path counts it held.
        app(RetentionHolds::class)->place($school->id, 'audit', 'CASE-43');
        $this->assertSame(['eligible' => 1, 'deleted' => 0, 'held' => 1], $expiry->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, $cutoff, 500, false));
        $this->assertTrue($this->auditExists($school, $old));

        app(RetentionHolds::class)->release($school->id, 'audit_closed', 'CASE-43');

        // A hold placed AFTER the PHP check (right after the dry-run count, as a concurrent operator would):
        // the database refuses the destructive call itself, and the run reports the rows held.
        $placed = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$placed, $school): void {
            if (! $placed && str_contains($e->sql, 'retention_expire_school_audit_events') && end($e->bindings) === 'true') {
                $placed = true;
                app(RetentionHolds::class)->place($school->id, 'audit', 'CASE-44');
            }
        });
        $this->assertSame(['eligible' => 1, 'deleted' => 0, 'held' => 1], app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, $cutoff, 500, false));
        $this->assertTrue($placed);
        $this->assertTrue($this->auditExists($school, $old), 'the database decided');
        app(RetentionHolds::class)->release($school->id, 'audit_closed', 'CASE-44');

        // On the retention connection only.
        $used = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$used): void {
            if (str_contains($e->sql, 'retention_expire_')) {
                $used[] = $e->connectionName;
            }
        });
        $this->assertSame(['eligible' => 1, 'deleted' => 1, 'held' => 0], app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, $cutoff, 500, false));
        $this->assertSame([RetentionExpiry::PRIVILEGED_CONNECTION], array_values(array_unique($used)));

        // No credential: nothing runs, no fallback to the runtime or migration connection.
        $this->schoolAudit($school, '2012-02-01 00:00:00');
        $connection = 'database.connections.'.RetentionExpiry::PRIVILEGED_CONNECTION;
        $username = config("{$connection}.username");
        config(["{$connection}.username" => null]);
        DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        try {
            $used = [];
            $this->assertSame(['eligible' => 0, 'deleted' => 0, 'held' => 0], app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, $cutoff, 500, false));
            $this->assertSame([], $used, 'no retention function ran on any connection');
        } finally {
            config(["{$connection}.username" => $username]);
            DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        }
    }

    #[Test]
    public function each_run_is_attributed_to_its_scope_category_and_the_maintenance_identity(): void
    {
        $school = $this->createSchool();
        $this->schoolAudit($school, '2012-01-01 00:00:00');
        Log::spy();

        app(RetentionExpiry::class)->forSchool(RetentionExpiry::SCHOOL_AUDIT, $school, now()->subYears(7), 500, false);
        app(RetentionExpiry::class)->forPlatform(RetentionExpiry::PLATFORM_AUDIT, now()->subYears(7), 500, true);

        Log::shouldHaveReceived('info')->with('retention.standalone_expired', [
            'category' => RetentionExpiry::SCHOOL_AUDIT, 'school_id' => $school->id, 'identity' => 'school_os_retention',
            'dry_run' => false, 'eligible' => 1, 'deleted' => 1, 'held' => 0,
        ])->once();
        // A run with nothing to report is not logged (no per-School noise).
        Log::shouldNotHaveReceived('info', fn (string $message, array $context = []) => $message === 'retention.standalone_expired'
            && $context['category'] === RetentionExpiry::PLATFORM_AUDIT && array_sum([$context['eligible'], $context['deleted'], $context['held']]) === 0);
    }

    #[Test]
    public function the_verifier_distinguishes_the_migrated_set_and_detects_a_regrant(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['privileged_retention_functions_closed', 'retention_role_functions_exact', 'retention_functions_narrow', 'retention_holds_authoritative', 'retention_role_read_only'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $admin->statement('GRANT EXECUTE ON FUNCTION retention_expire_school_audit_events(uuid, timestamp, integer, boolean) TO school_os_app');
        try {
            $this->assertSame(CheckResult::FAIL, $checks()['privileged_retention_functions_closed']->status, 'the runtime role regaining EXECUTE is detected');
        } finally {
            $admin->statement('REVOKE EXECUTE ON FUNCTION retention_expire_school_audit_events(uuid, timestamp, integer, boolean) FROM school_os_app');
        }
        $this->assertSame(CheckResult::PASS, $checks()['privileged_retention_functions_closed']->status);
    }
}
