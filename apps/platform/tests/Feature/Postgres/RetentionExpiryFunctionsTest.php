<?php

namespace Tests\Feature\Postgres;

use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2B: the narrow retention functions, on the real runtime role. Protected
 * history stays undeletable by the runtime role. The only way out is a fixed
 * function that refuses a young cutoff and another School.
 */
class RetentionExpiryFunctionsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function refused(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("expected refusal: {$needle}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    #[Test]
    public function the_runtime_role_still_cannot_delete_any_protected_history_directly(): void
    {
        $school = $this->createSchool();

        foreach (['school_audit_events', 'membership_role_assignments', 'teaching_assignments', 'communication_delivery_policy_decisions'] as $table) {
            app(TenantContext::class)->withSchool($school, fn () => $this->refused(fn () => DB::table($table)->where('school_id', $school->id)->delete(), 'permission denied'));
        }
        foreach (['platform_audit_events', 'email_suppressions', 'school_elevations', 'group_role_assignments', 'platform_role_assignments'] as $table) {
            $this->refused(fn () => DB::table($table)->whereRaw('false')->delete(), 'permission denied');
        }
    }

    #[Test]
    public function no_caller_can_expire_a_row_before_its_adopted_period(): void
    {
        $school = $this->createSchool();
        $young = now('UTC')->subYears(7)->addDay()->format('Y-m-d H:i:s');

        app(TenantContext::class)->withSchool($school, function () use ($school, $young) {
            $this->refused(fn () => DB::select('select retention_expire_school_audit_events(?, ?, 10, false)', [$school->id, $young]), 'retention_floor');
            $this->refused(fn () => DB::select('select retention_expire_membership_role_assignments(?, ?, 10, false)', [$school->id, $young]), 'retention_floor');
            $this->refused(fn () => DB::select('select retention_expire_school_elevations(?, ?, 10, false)', [$school->id, $young]), 'retention_floor');
            $this->refused(fn () => DB::select('select retention_expire_teaching_assignments(?, ?, 10, false)', [$school->id, now('UTC')->subYears(7)->addDays(2)->toDateString()]), 'retention_floor');
        });

        $this->refused(fn () => DB::select('select retention_expire_platform_audit_events(?, 10, false)', [$young]), 'retention_floor');
        $this->refused(fn () => DB::select('select retention_expire_group_role_assignments(?, 10, false)', [$young]), 'retention_floor');
        $this->refused(fn () => DB::select('select retention_expire_platform_role_assignments(?, 10, false)', [$young]), 'retention_floor');
        // One year for released suppressions and (E21.2C) delivery policy decisions.
        app(TenantContext::class)->withSchool($school, fn () => $this->refused(
            fn () => DB::select('select retention_expire_communication_delivery_policy_decisions(?, ?, 10, false)', [$school->id, now('UTC')->subYear()->addDay()->format('Y-m-d H:i:s')]),
            'retention_floor',
        ));
        $this->refused(fn () => DB::select('select retention_expire_released_email_suppressions(?, 10, false)', [now('UTC')->subYear()->addDay()->format('Y-m-d H:i:s')]), 'retention_floor');
    }

    #[Test]
    public function a_school_function_only_ever_acts_for_the_callers_own_tenant_context(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $old = now('UTC')->subYears(10)->format('Y-m-d H:i:s');

        // No context, or another School's context: refused before anything runs.
        $this->refused(fn () => DB::select('select retention_expire_school_audit_events(?, ?, 10, false)', [$a->id, $old]), 'retention_tenant');
        app(TenantContext::class)->withSchool($b, fn () => $this->refused(
            fn () => DB::select('select retention_expire_school_audit_events(?, ?, 10, false)', [$a->id, $old]),
            'retention_tenant',
        ));
        app(TenantContext::class)->withSchool($b, fn () => $this->refused(
            fn () => DB::select('select retention_expire_communication_delivery_policy_decisions(?, ?, 10, false)', [$a->id, $old]),
            'retention_tenant',
        ));
    }

    #[Test]
    public function the_helpers_are_not_executable_and_the_verifier_proves_the_grants_are_narrow(): void
    {
        $this->refused(fn () => DB::select("select retention_assert_floor(now()::timestamp, interval '1 day')"), 'permission denied');
        $this->refused(fn () => DB::select('select retention_assert_tenant(?)', [(string) Str::uuid()]), 'permission denied');

        $check = collect(app(DatabaseRoleVerifier::class)->verify())->firstWhere('code', 'retention_functions_narrow');
        $this->assertNotNull($check);
        $this->assertSame(CheckResult::PASS, $check->status);
    }

    #[Test]
    public function only_the_retention_gateway_calls_the_functions(): void
    {
        $output = [];
        exec('grep -rln --include=*.php '.escapeshellarg('retention_expire_').' '.escapeshellarg(app_path()).' 2>/dev/null', $output);

        $this->assertSame([app_path('Support/Retention/RetentionExpiry.php')], array_values(array_diff($output, [app_path('Support/Operations/DatabaseRoleVerifier.php')])));
    }
}
