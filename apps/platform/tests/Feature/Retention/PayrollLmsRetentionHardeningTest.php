<?php

namespace Tests\Feature\Retention;

use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21-RH.5 (ADR 0066 §13): the Payroll and LMS unit functions run only as
 * the dedicated retention identity and refuse any other session user and
 * any active platform or School hold themselves (dry runs included); the
 * four RH.6 functions are untouched. The units' own behaviour, holds and
 * races are proven in PayrollRetentionGuardTest,
 * AcademicOperationsRetentionTest and the two concurrency tests.
 *
 * COMMITTED fixtures (the retention and maintenance connections are their
 * own sessions).
 */
class PayrollLmsRetentionHardeningTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    /** function => a call with harmless arguments ([school, ...]); every one is School-scoped. */
    private const UNITS = [
        'retention_expire_payroll_employee_evidence' => 'SELECT retention_expire_payroll_employee_evidence(?, ?, ?, true)',
        'retention_expire_payroll_run' => 'SELECT retention_expire_payroll_run(?, ?, ?, true)',
        'retention_expire_learning_content' => 'SELECT retention_expire_learning_content(?, ?, ?, true)',
        'retention_expire_assignment' => 'SELECT retention_expire_assignment(?, ?, ?, true)',
        'retention_expire_lms_resource' => "SELECT * FROM retention_expire_lms_resource('learning_content', ?, ?, ?)",
    ];

    private const RH6 = [
        'retention_expire_finance_unit', 'retention_expire_student_processing_authorizations',
        'retention_expire_student_consent_events', 'retention_expire_guardian_consent_events',
    ];

    /** The refusal running UNITS[$function] on $connection in $schoolId's context ('' if it ran). */
    private function invoke(string $connection, string $function, string $schoolId): string
    {
        $cutoff = $function === 'retention_expire_payroll_run' ? '2010-01-01 00:00:00' : '2010-01-01';
        try {
            DB::connection($connection)->transaction(function () use ($connection, $function, $schoolId, $cutoff): void {
                DB::connection($connection)->select("SELECT set_config('app.current_school_id', ?, true)", [$schoolId]);
                DB::connection($connection)->select(self::UNITS[$function], [$schoolId, (string) Str::uuid7(), $cutoff]);
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }

    #[Test]
    public function the_unit_functions_are_retention_only_definers_and_the_rh6_functions_are_untouched(): void
    {
        $catalog = fn (string $f) => DB::selectOne("SELECT pg_get_userbyid(p.proowner) AS owner, p.prosecdef, array_to_string(p.proconfig, ',') AS cfg, p.prosrc AS src,
                has_function_privilege('school_os_app', p.oid, 'EXECUTE') AS runtime, has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS retention,
                EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0) AS public
           FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?", [$f]);

        foreach (array_keys(self::UNITS) as $function) {
            $f = $catalog($function);
            $this->assertSame([false, true, false, true], [(bool) $f->runtime, (bool) $f->retention, (bool) $f->public, (bool) $f->prosecdef], $function);
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $f->cfg, $function);
            $this->assertNotContains($f->owner, ['school_os_app', 'school_os_retention'], $function);
            $this->assertMatchesRegularExpression('/BEGIN\s+(--[^\n]*\n\s*)?PERFORM public\.retention_assert_retention_identity\(\);\s+PERFORM public\.retention_assert_not_held\(p_school_id\);/', (string) $f->src, "{$function}: identity and hold first, dry runs included");
        }
        foreach (self::RH6 as $function) {
            $f = $catalog($function);
            $this->assertSame([true, false], [(bool) $f->runtime, (bool) $f->retention], "{$function}: RH.6, unchanged");
            $this->assertStringNotContainsString('retention_assert_retention_identity', (string) $f->src, $function);
        }
        $this->assertEqualsCanonicalizing(self::RH6, DatabaseRoleVerifier::RETENTION_FUNCTIONS);
        $this->assertEqualsCanonicalizing(array_keys(self::UNITS), DatabaseRoleVerifier::UNIT_RETENTION_FUNCTIONS);
    }

    #[Test]
    public function only_the_retention_login_reaches_the_bodies_and_a_hold_refuses_inside_postgresql(): void
    {
        $school = $this->createSchool();
        foreach (array_keys(self::UNITS) as $function) {
            $this->assertStringContainsString("permission denied for function {$function}", $this->invoke('pgsql', $function, $school->id), "{$function}: runtime");
            $this->assertStringContainsString('retention_privilege', $this->invoke(RetentionHolds::MAINTENANCE_CONNECTION, $function, $school->id), "{$function}: the owner login is not the retention identity");
            // Reachable: past the prologue, its own checks speak (an unknown unit, never a privilege error).
            $reached = $this->invoke(RetentionExpiry::PRIVILEGED_CONNECTION, $function, $school->id);
            $this->assertStringNotContainsString('permission denied', $reached, $function);
            $this->assertStringNotContainsString('retention_privilege', $reached, $function);
        }
        foreach (self::RH6 as $function) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_function_privilege('school_os_retention', p.oid, 'EXECUTE') AS x FROM pg_proc p WHERE p.proname = ?", [$function])->x, "{$function}: out of the retention identity's reach");
        }

        // A School hold, in the database only, refuses every unit function before anything else.
        app(RetentionHolds::class)->place($school->id, 'litigation', 'RH5-HOLD');
        foreach (array_keys(self::UNITS) as $function) {
            $this->assertStringContainsString('retention_hold', $this->invoke(RetentionExpiry::PRIVILEGED_CONNECTION, $function, $school->id), $function);
        }
    }

    #[Test]
    public function the_verifier_proves_the_rh5_state_and_detects_a_regrant_or_a_wider_read(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['privileged_retention_functions_closed', 'retention_role_functions_exact', 'retention_functions_narrow', 'retention_role_read_only', 'retention_role_selects_exact', 'retention_holds_authoritative'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $regressions = [
            'privileged_retention_functions_closed' => ['GRANT EXECUTE ON FUNCTION retention_expire_payroll_run(uuid, uuid, timestamp, boolean) TO school_os_app', 'REVOKE EXECUTE ON FUNCTION retention_expire_payroll_run(uuid, uuid, timestamp, boolean) FROM school_os_app'],
            'retention_role_selects_exact' => ['GRANT SELECT (title) ON learning_content TO school_os_retention', 'REVOKE SELECT (title) ON learning_content FROM school_os_retention'],
            'retention_role_functions_exact' => ['GRANT EXECUTE ON FUNCTION retention_expire_guardian_consent_events(uuid, uuid, timestamp, boolean) TO school_os_retention', 'REVOKE EXECUTE ON FUNCTION retention_expire_guardian_consent_events(uuid, uuid, timestamp, boolean) FROM school_os_retention'],
        ];
        foreach ($regressions as $code => [$break, $restore]) {
            $admin->statement($break);
            try {
                $this->assertSame(CheckResult::FAIL, $checks()[$code]->status, "{$code} detects: {$break}");
            } finally {
                $admin->statement($restore);
            }
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }
    }
}
