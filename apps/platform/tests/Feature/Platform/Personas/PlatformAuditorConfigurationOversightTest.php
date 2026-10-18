<?php

namespace Tests\Feature\Platform\Personas;

use App\Domain\Platform\Application\Elevation\ElevationDeniedException;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementDeniedException;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\TestCase;
use Throwable;

/**
 * ORG.6 (ADR 0076 §8, §32): the Platform Auditor's read-only configuration
 * oversight -- the Registry overview including module entitlement STATE,
 * under the existing platform.school_entitlements.view, with no fresh MFA to
 * read it, no mutation of any kind (a valid MFA code never substitutes for
 * a missing capability), no tenant business data and no School audit ledger.
 */
class PlatformAuditorConfigurationOversightTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    /** Tenant business tables a configuration read must never touch. */
    private const BUSINESS_TABLES = [
        'students', 'student_enrollments', 'guardians', 'employees', 'employment_records', 'payroll_runs', 'payslips',
        'attendance_sessions', 'attendance_records', 'staff_attendance_records', 'student_marks', 'charges', 'payments',
        'journal_entries', 'documents', 'communication_messages', 'school_audit_events', 'leave_requests',
    ];

    private function persona(User $root, string $role): User
    {
        $user = $this->createUser();
        $this->assignPlatformRole($user, $role, $root);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);
        $this->mfaSecrets[$user->id] = $secret;

        return $user;
    }

    private function request(): Request
    {
        $request = Request::create('/app/platform/schools', 'POST');
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function lastStep(User $user): ?int
    {
        return UserMfaFactor::query()->where('user_id', $user->id)->value('last_used_totp_step');
    }

    #[Test]
    public function the_auditor_holds_exactly_four_read_capabilities(): void
    {
        $auditor = $this->persona($this->platformAdmin(), 'platform_auditor');
        $resolver = app(CapabilityResolver::class);

        $this->assertEqualsCanonicalizing(
            ['platform.audit.view', 'platform.schools.view', 'platform.school_groups.view', 'platform.school_entitlements.view'],
            $resolver->platformCapabilities($auditor),
        );
        foreach ([
            'platform.school_entitlements.manage', 'platform.schools.onboard', 'platform.schools.manage', 'platform.role_grants.manage',
            'platform.school_groups.manage', 'platform.school_group_grants.manage', 'platform.schools.elevate', 'platform.operations.view',
            'platform.feature_flags.view', 'platform.feature_flags.manage', 'platform.users.mfa.reset',
        ] as $capability) {
            $this->assertFalse($resolver->canPlatform($auditor, $capability), $capability);
        }
        $this->assertSame([], $resolver->schoolCapabilities($auditor, $this->createSchool()));

        // Grants name a role, never capabilities: an existing Auditor grant
        // resolves the updated code-owned role at once (no per-grant data).
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('platform_role_assignments', 'capability_key'));
    }

    #[Test]
    public function the_auditor_reads_module_state_on_the_overview_without_fresh_mfa(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->persona($root, 'platform_auditor');
        $school = $this->createSchool();
        SchoolFactory::setOptionalModules($school, false, ['library', 'canteen']);

        // An ordinary signed-in session: no MFA assurance.
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('modules', count(ModuleCatalogue::keys()))
            ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'library')['enabled'] === false
                && collect($modules)->firstWhere('key', 'fees')['enabled'] === true
                && collect($modules)->where('core', true)->every(fn ($m) => $m['enabled'] === true)
                && collect($modules)->firstWhere('key', 'attendance')['dependsOn'] === ['timetable'])
            ->where('canManageModules', false)
            ->where('canManage', false)->where('canOnboard', false)
            ->where('audit.available', false)->where('audit.reason', 'mfa'));

        // Historical evidence keeps the stronger contract: current MFA assurance.
        $this->actingAs($auditor)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('audit.available', true));
    }

    #[Test]
    public function the_auditor_sees_a_change_immediately_and_only_for_that_school(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->persona($root, 'platform_manager');
        $auditor = $this->persona($root, 'platform_auditor');
        $a = $this->createSchool();
        $b = $this->createSchool();
        $service = app(SchoolModuleEntitlementService::class);
        $state = fn (School $school, string $module) => collect($service->overview($school))->firstWhere('key', $module)['enabled'];

        $this->assertTrue($state($a, 'visitor'));
        $this->resetLifecycleLimiter($manager);
        $this->actingAs($manager)->post("/app/platform/schools/{$a->id}/modules/visitor/disable", [
            'reason_code' => 'school_request', 'confirmed' => '1', 'mfa_code' => $this->freshCode($manager),
        ])->assertSessionHasNoErrors();

        $this->actingAs($auditor)->get("/app/platform/schools/{$a->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'visitor')['enabled'] === false));
        $this->actingAs($auditor)->get("/app/platform/schools/{$b->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'visitor')['enabled'] === true));
        $this->assertNull(app(TenantContext::class)->school(), 'no School context is left behind');
    }

    #[Test]
    public function a_configuration_read_never_touches_tenant_business_tables(): void
    {
        $auditor = $this->persona($this->platformAdmin(), 'platform_auditor');
        $school = $this->createSchool();
        $this->createCampus($school);

        DB::enableQueryLog();
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}")->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        foreach ($queries as $sql) {
            foreach (self::BUSINESS_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+"?'.$table.'"?\b/i', $sql, "the overview read {$table}");
            }
        }
        $this->assertNotEmpty(array_filter($queries, fn ($q) => str_contains($q, 'school_module_entitlements')), 'module state read through the entitlement reader');
    }

    #[Test]
    public function the_auditor_reaches_no_mutation_route(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->persona($root, 'platform_auditor');
        $school = $this->createSchool();
        $provisioning = $this->createSchool(['status' => 'provisioning']);
        $group = $this->createGroup([$school]);
        $this->actingAs($auditor);

        foreach (["/app/platform/schools/{$school->id}/modules/library/disable", "/app/platform/schools/{$school->id}/modules/library/enable", '/app/platform/schools/create',
            "/app/platform/schools/{$provisioning->id}/activate", "/app/platform/schools/{$school->id}/suspend", "/app/platform/schools/{$school->id}/close",
            "/app/platform/schools/{$provisioning->id}/bootstrap-admin", '/app/platform/roles', '/app/platform/elevation'] as $page) {
            $this->get($page)->assertForbidden();
        }

        $code = fn () => $this->freshCode($auditor);
        $this->resetLifecycleLimiter($auditor);
        foreach ([
            ["/app/platform/schools/{$school->id}/modules/library/disable", ['reason_code' => 'school_request', 'confirmed' => '1']],
            ["/app/platform/schools/{$school->id}/modules/library/enable", ['reason_code' => 'school_request', 'confirmed' => '1']],
            ['/app/platform/schools', ['name' => 'Auditor School', 'slug' => 'auditor-school', 'admin' => $this->createUser()->email, 'confirmed' => '1']],
            ["/app/platform/schools/{$provisioning->id}/activate", ['confirmed' => '1']],
            ["/app/platform/schools/{$school->id}/suspend", ['reason_code' => 'administrative_hold', 'confirmed' => '1']],
            ["/app/platform/schools/{$school->id}/close", ['reason_code' => 'contract_ended', 'confirmed' => '1']],
            ['/app/platform/roles/grants', ['user' => $this->createUser()->email, 'role' => 'platform_auditor']],
            ['/app/platform/groups', ['name' => 'Auditor Trust', 'slug' => 'auditor-trust']],
            ["/app/platform/groups/{$group->id}/grants", ['user' => $this->createUser()->email]],
            ['/app/platform/elevation', ['target' => $school->id, 'reason_code' => 'operational_support', 'confirmed' => '1']],
        ] as [$url, $payload]) {
            $this->resetLifecycleLimiter($auditor);
            // A VALID fresh code never compensates for the missing capability.
            $this->post($url, [...$payload, 'mfa_code' => $code(), 'code' => $code()])->assertForbidden();
        }

        $this->assertTrue(collect(app(SchoolModuleEntitlementService::class)->overview($school))->firstWhere('key', 'library')['enabled']);
        $this->assertTrue($provisioning->fresh()->isProvisioning());
        $this->assertTrue($school->fresh()->isActive());
        $this->assertSame(0, School::query()->where('slug', 'auditor-school')->count());
    }

    #[Test]
    public function every_mutation_service_refuses_the_auditor_before_spending_a_code(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->persona($root, 'platform_auditor');
        $school = $this->createSchool();
        $provisioning = $this->createSchool(['status' => 'provisioning']);
        $entitlements = app(SchoolModuleEntitlementService::class);
        $lifecycle = app(SchoolLifecycleService::class);

        $this->assertTrue($entitlements->canView($auditor));
        $this->assertFalse($entitlements->canManage($auditor));
        $this->assertCount(count(ModuleCatalogue::keys()), $entitlements->overview($school));

        $before = $this->lastStep($auditor);
        $refusals = [
            fn () => $entitlements->enable($this->request(), $auditor, $school, 'library', 'school_request', true, $this->freshCode($auditor)),
            fn () => $entitlements->disable($this->request(), $auditor, $school, 'library', 'school_request', true, $this->freshCode($auditor)),
            fn () => $lifecycle->create($this->request(), $auditor, ['name' => 'X', 'slug' => 'auditor-x', 'admin' => $this->createUser()->email], true, $this->freshCode($auditor)),
            fn () => $lifecycle->activate($this->request(), $auditor, $provisioning, true, $this->freshCode($auditor)),
            fn () => $lifecycle->suspend($this->request(), $auditor, $school, 'administrative_hold', true, $this->freshCode($auditor)),
            fn () => $lifecycle->close($this->request(), $auditor, $school, 'contract_ended', true, $this->freshCode($auditor)),
            fn () => app(SchoolElevationService::class)->start($this->request(), $auditor, $school->id, 'operational_support', true, $this->freshCode($auditor)),
            fn () => app(PlatformRoleGovernanceService::class)->grant($this->request(), $auditor, $this->createUser()->email, 'platform_auditor'),
        ];
        foreach ($refusals as $i => $operation) {
            try {
                $operation();
                $this->fail("operation {$i} must be refused");
            } catch (SchoolModuleEntitlementDeniedException|SchoolLifecycleDeniedException|ElevationDeniedException|HttpException $e) {
                if ($e instanceof HttpException) {
                    $this->assertSame(403, $e->getStatusCode(), "operation {$i}");
                }
            } catch (Throwable $e) {
                $this->fail("operation {$i} refused with an unexpected ".$e::class.': '.$e->getMessage());
            }
        }

        $this->assertSame($before, $this->lastStep($auditor), 'no refusal consumed a code: capability first');
        $outcomes = PlatformAuditEvent::query()->where('actor_user_id', $auditor->id)->where('event_type', SchoolModuleEntitlementService::DENIED_EVENT)->pluck('metadata')->pluck('outcome_code')->all();
        $this->assertSame(['capability_missing', 'capability_missing'], $outcomes);
    }

    #[Test]
    public function school_personas_reach_no_platform_configuration(): void
    {
        $school = $this->createSchool();
        [$schoolAdmin] = [$this->createUserWithCapabilities($school, ['school.settings.manage', 'school.profile.manage', 'school.members.manage', 'school.roles.manage'])];
        [$teacher] = $this->createSchoolAdmin('teacher');
        $guardian = $this->portalGuardian($school)['user'];

        foreach ([$schoolAdmin, $teacher, $guardian] as $user) {
            $this->actingAs($user);
            $this->get('/app/platform/schools')->assertForbidden();
            $this->get("/app/platform/schools/{$school->id}")->assertForbidden();
            $this->get("/app/platform/schools/{$school->id}/modules/library/disable")->assertForbidden();
            $this->get('/app/platform/audit-log')->assertForbidden();
            $this->assertSame([], app(CapabilityResolver::class)->platformCapabilities($user));
        }
    }
}
