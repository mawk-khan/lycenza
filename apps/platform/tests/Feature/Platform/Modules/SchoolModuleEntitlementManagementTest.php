<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementDeniedException;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService;
use App\Domain\Platform\Infrastructure\SchoolModuleEntitlement;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §12, §31): Platform Admin and Platform Manager enable and
 * disable OPTIONAL modules per School -- fresh MFA, a closed reason, explicit
 * confirmation, dependencies, platform audit. A disabled module's
 * capabilities resolve to nothing on every surface (navigation, web, API,
 * services) without any role change, and enabling it restores them.
 */
class SchoolModuleEntitlementManagementTest extends TestCase
{
    use CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    private const VISITOR_CAPABILITIES = ['visitor.directory.view', 'visitor.directory.manage', 'school.profile.view'];

    private function manager(User $root): User
    {
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($manager, $secret);
        $this->mfaSecrets[$manager->id] = $secret;

        return $manager;
    }

    private function auditor(User $root): User
    {
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($auditor, $secret);
        $this->mfaSecrets[$auditor->id] = $secret;

        return $auditor;
    }

    /** @param  array<string, mixed>  $overrides */
    private function change(User $actor, School $school, string $module, string $action, array $overrides = []): TestResponse
    {
        $this->resetLifecycleLimiter($actor);
        $this->actingAs($actor);

        return $this->post("/app/platform/schools/{$school->id}/modules/{$module}/{$action}", array_merge([
            'reason_code' => 'school_request',
            'confirmed' => '1',
            'mfa_code' => isset($this->mfaSecrets[$actor->id]) ? $this->freshCode($actor) : '000000',
        ], $overrides));
    }

    private function state(School $school, string $module): ?bool
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolModuleEntitlement::query()
            ->where('school_id', $school->id)->where('module_key', $module)->value('enabled'));
    }

    /** @return list<string> outcome codes of the actor's refusals */
    private function denials(User $actor): array
    {
        return PlatformAuditEvent::query()->where('actor_user_id', $actor->id)->where('event_type', SchoolModuleEntitlementService::DENIED_EVENT)
            ->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->map(fn ($m) => $m['outcome_code'])->all();
    }

    private function staffIn(School $school): User
    {
        return $this->createUserWithCapabilities($school, self::VISITOR_CAPABILITIES);
    }

    // -- Enforcement on every surface, and restoration -------------------------------

    #[Test]
    public function disabling_removes_access_everywhere_and_enabling_restores_it_without_role_changes(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();
        $staff = $this->staffIn($school);
        $grantsBefore = app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_id', $school->id)->get(['id', 'role_id', 'revoked_at'])->toArray());
        $resolver = app(CapabilityResolver::class);

        $this->actingAs($staff)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/visitor/directory')->assertOk();
        $this->getJson("/api/v1/schools/{$school->id}/visitors")->assertOk();
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('navigation', fn ($nav) => str_contains(json_encode($nav), 'visitor-directory')));

        // Disable: MFA, reason, confirmation.
        $this->change($root, $school, 'visitor', 'disable')->assertSessionHasNoErrors()->assertRedirect("/app/platform/schools/{$school->id}");
        $this->assertFalse($this->state($school, 'visitor'));

        // Immediately: resolver, navigation, web, API.
        $this->assertNotContains('visitor.directory.view', $resolver->schoolCapabilities($staff, $school));
        $this->assertContains('school.profile.view', $resolver->schoolCapabilities($staff, $school), 'Core capabilities stay.');
        $this->actingAs($staff);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('navigation', fn ($nav) => ! str_contains(json_encode($nav), 'visitor-directory')));
        $this->get('/app/visitor/directory')->assertForbidden();
        $this->getJson("/api/v1/schools/{$school->id}/visitors")->assertForbidden();
        $this->postJson("/api/v1/schools/{$school->id}/visitors", ['full_name' => 'X'])->assertForbidden();

        // Nothing about the School's roles or data changed.
        $this->assertSame($grantsBefore, app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_id', $school->id)->get(['id', 'role_id', 'revoked_at'])->toArray()));

        // Enable again: the same grants take effect again.
        $this->change($root, $school, 'visitor', 'enable', ['reason_code' => 'operational_correction'])->assertSessionHasNoErrors();
        $this->assertTrue($this->state($school, 'visitor'));
        $this->assertContains('visitor.directory.view', $resolver->schoolCapabilities($staff, $school));
        $this->actingAs($staff);
        $this->get('/app/visitor/directory')->assertOk();
        $this->getJson("/api/v1/schools/{$school->id}/visitors")->assertOk();

        // Platform audit only -- never the School ledger.
        $events = PlatformAuditEvent::query()->where('subject_id', $school->id)->whereIn('event_type', [SchoolModuleEntitlementService::ENABLED_EVENT, SchoolModuleEntitlementService::DISABLED_EVENT])->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame([SchoolModuleEntitlementService::DISABLED_EVENT, SchoolModuleEntitlementService::ENABLED_EVENT], $events->pluck('event_type')->all());
        $this->assertSame($root->id, $events[0]->actor_user_id);
        $this->assertSame(['visitor', 'enabled', 'disabled', 'school_request'], [$events[0]->metadata['module_key'], $events[0]->metadata['from'], $events[0]->metadata['to'], $events[0]->metadata['reason_code']]);
        $this->assertGreaterThan(0, $events[0]->metadata['affected_capabilities']);
        $this->assertGreaterThanOrEqual(1, $events[0]->metadata['affected_active_grants']);
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'like', '%entitlement%')->count()));
    }

    #[Test]
    public function owned_teacher_capabilities_follow_their_own_module(): void
    {
        $root = $this->platformAdmin();
        [$teacher, $school] = $this->createSchoolAdmin('teacher');
        $resolver = app(CapabilityResolver::class);
        $this->assertContains('attendance.teacher', $resolver->schoolCapabilities($teacher, $school));

        // Enabled: the capability alone is not enough -- ownership (a linked
        // ActingEmployee) is still required. Entitlement AND capability AND ownership.
        $this->assertThrows(fn () => app(TeacherAttendanceAccess::class)->scope($teacher, $school), ActingEmployeeUnavailableException::class);

        // Attendance depends on Timetable: Timetable cannot go first.
        $this->change($root, $school, 'timetable', 'disable')->assertSessionHasErrors('module');
        $this->change($root, $school, 'attendance', 'disable')->assertSessionHasNoErrors();

        $capabilities = $resolver->schoolCapabilities($teacher, $school);
        $this->assertNotContains('attendance.teacher', $capabilities);
        $this->assertContains('lms.content.teacher', $capabilities, 'Other owned capabilities keep their own modules.');
        $this->actingAs($teacher)->post("/app/schools/{$school->id}/activate");
        $this->withSession(['mfa_verified_at' => now()->toIso8601String()])->get('/app/my-attendance')->assertForbidden();
        // Disabled: refused at the capability, before ownership is even considered.
        $this->assertThrows(fn () => app(TeacherAttendanceAccess::class)->scope($teacher, $school), AuthorizationException::class);
    }

    #[Test]
    public function a_role_granted_while_the_module_is_disabled_confers_nothing_until_it_is_enabled(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();
        $this->change($root, $school, 'visitor', 'disable')->assertSessionHasNoErrors();

        // The grant commits (no new refusal semantics, ADR 0076 §12.6): the resolver intersection is authoritative.
        $late = $this->staffIn($school);
        $this->assertNotContains('visitor.directory.view', app(CapabilityResolver::class)->schoolCapabilities($late, $school));

        $this->change($root, $school, 'visitor', 'enable')->assertSessionHasNoErrors();
        $this->assertContains('visitor.directory.view', app(CapabilityResolver::class)->schoolCapabilities($late, $school));
    }

    #[Test]
    public function platform_reads_and_writes_restore_the_callers_tenant_context(): void
    {
        $root = $this->platformAdmin();
        $target = $this->createSchool();
        $other = $this->createSchool();
        $context = app(TenantContext::class);
        $service = app(SchoolModuleEntitlementService::class);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session')->driver());

        $context->set($other);
        try {
            $service->overview($target);
            $service->impact($target, 'library');
            $service->disable($request, $root, $target, 'library', 'platform_policy', true, $this->freshCode($root));
            $this->assertSame($other->id, $context->requireSchool()->id);
        } finally {
            $context->clearAll();
        }
        $this->assertFalse($this->state($target, 'library'));
        $this->assertTrue($this->state($other, 'library'));
    }

    // -- Personas ------------------------------------------------------------------

    #[Test]
    public function the_platform_manager_manages_modules_and_the_auditor_cannot(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $auditor = $this->auditor($root);
        $school = $this->createSchool();

        $this->actingAs($manager)->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('modules', count(ModuleCatalogue::keys()))->where('canManageModules', true));
        $this->actingAs($manager)->get("/app/platform/schools/{$school->id}/modules/library/disable")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/Schools/Module')->where('module.enabled', true)->has('impact'));
        $this->change($manager, $school, 'library', 'disable')->assertSessionHasNoErrors();
        $this->assertFalse($this->state($school, 'library'));

        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('modules', count(ModuleCatalogue::keys()))->where('canManageModules', false));
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}/modules/library/enable")->assertForbidden();
        $this->change($auditor, $school, 'library', 'enable')->assertForbidden();
        $this->assertFalse($this->state($school, 'library'));
        $this->assertSame(['capability_missing'], $this->denials($auditor));

        // A School administrator has no path at all.
        [$schoolAdmin] = [$this->createUserWithCapabilities($school, ['school.settings.manage', 'school.profile.manage'])];
        $this->change($schoolAdmin, $school, 'library', 'enable')->assertForbidden();
        $this->assertFalse($this->state($school, 'library'));
    }

    // -- Refusals ------------------------------------------------------------------

    #[Test]
    public function core_and_unknown_modules_are_refused_and_audited(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();

        $this->change($root, $school, 'student_records', 'disable')->assertSessionHasErrors('module');
        $this->change($root, $school, 'billing', 'enable')->assertSessionHasErrors('module');
        $this->actingAs($root)->get("/app/platform/schools/{$school->id}/modules/student_records/disable")->assertNotFound();
        $this->actingAs($root)->get("/app/platform/schools/{$school->id}/modules/billing/enable")->assertNotFound();

        $this->assertSame(['core_module', 'unknown_module'], $this->denials($root));
        $denial = PlatformAuditEvent::query()->where('actor_user_id', $root->id)->where('event_type', SchoolModuleEntitlementService::DENIED_EVENT)->orderBy('occurred_at')->orderBy('id')->get()->last();
        $this->assertArrayNotHasKey('module_key', $denial->metadata, 'An unknown key is never recorded.');
    }

    #[Test]
    public function dependencies_are_enforced_in_both_directions_and_nothing_cascades(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();

        // Fees cannot be disabled while Library and Canteen depend on it.
        $this->change($root, $school, 'fees', 'disable')->assertSessionHasErrors('module');
        $this->assertTrue($this->state($school, 'fees'));

        $this->change($root, $school, 'library', 'disable')->assertSessionHasNoErrors();
        $this->change($root, $school, 'canteen', 'disable')->assertSessionHasNoErrors();
        $this->change($root, $school, 'fees', 'disable')->assertSessionHasNoErrors();
        $this->assertTrue($this->state($school, 'finance'), 'Disabling Fees never disables Finance.');

        // Library cannot be enabled before Fees; enabling Fees enables nothing else.
        $this->change($root, $school, 'library', 'enable')->assertSessionHasErrors('module');
        $this->change($root, $school, 'fees', 'enable')->assertSessionHasNoErrors();
        $this->assertFalse($this->state($school, 'library'));
        $this->change($root, $school, 'library', 'enable')->assertSessionHasNoErrors();

        $this->assertSame(['dependents_enabled', 'dependency_disabled'], $this->denials($root));
    }

    #[Test]
    public function mfa_reason_confirmation_and_no_op_changes_are_refused(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();

        $this->change($root, $school, 'library', 'disable', ['reason_code' => 'paid_plan'])->assertSessionHasErrors('reason_code');
        $this->change($root, $school, 'library', 'disable', ['confirmed' => '0'])->assertSessionHasErrors('confirmed');
        $this->change($root, $school, 'library', 'disable', ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->change($root, $school, 'library', 'enable')->assertSessionHasErrors('module');
        $this->assertTrue($this->state($school, 'library'));

        // Only the MFA failure is a refusal; validation is not audited.
        $this->assertSame(['mfa_verification_failed'], $this->denials($root));

        $noFactor = $this->createPlatformRoot();
        $this->change($noFactor, $school, 'library', 'disable')->assertSessionHasErrors('mfa_code');
        $this->assertSame(['mfa_not_enrolled'], $this->denials($noFactor));
    }

    #[Test]
    public function a_closed_school_refuses_changes_and_provisioning_and_suspended_schools_allow_them(): void
    {
        $root = $this->platformAdmin();
        $active = $this->activeSchoolViaPlatform($root, $this->createUser());
        $this->lifecycleAction($root, $active, 'close', ['reason_code' => 'contract_ended'])->assertSessionHasNoErrors();

        $this->actingAs($root)->get("/app/platform/schools/{$active->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('canManageModules', false));
        $this->change($root, $active, 'library', 'enable')->assertSessionHasErrors('module');
        $this->assertSame(['school_closed'], $this->denials($root));

        $provisioning = $this->createSchool(['status' => 'provisioning']);
        $this->change($root, $provisioning, 'library', 'disable')->assertSessionHasNoErrors();
        $suspended = $this->createSchool(['status' => 'suspended']);
        $this->change($root, $suspended, 'library', 'disable')->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_service_refuses_directly_without_the_route(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->auditor($root);
        $school = $this->createSchool();
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session')->driver());

        try {
            app(SchoolModuleEntitlementService::class)->disable($request, $auditor, $school, 'library', 'school_request', true, $this->freshCode($auditor));
            $this->fail('The Auditor must be refused by the service itself.');
        } catch (SchoolModuleEntitlementDeniedException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertTrue($this->state($school, 'library'));
    }

    // -- Onboarding: a new School is core-only ----------------------------------------

    #[Test]
    public function a_school_onboarded_by_the_manager_starts_core_only_and_activation_needs_no_module(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $admin = $this->createUser();
        // A request field naming modules is ignored: onboarding never opts in.
        $school = $this->provisionSchool($manager, $admin, ['modules' => ['communications', 'visitor'], 'entitlements' => ['visitor' => true]]);
        $this->assertTrue($school->isProvisioning());

        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolModuleEntitlement::query()->where('school_id', $school->id)->count()), 'No rows: every optional module is disabled.');
        $this->assertSame(ModuleCatalogue::coreKeys(), app(SchoolModuleEntitlements::class)->entitledModules($school));
        $this->actingAs($manager)->get("/app/platform/schools/{$school->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('modules', fn ($modules) => collect($modules)->every(fn ($m) => $m['enabled'] === $m['core'])));

        // The bootstrap administrator contract is unchanged.
        $this->assertSame([$admin->id], array_map(fn ($m) => $m->user_id, app(SchoolBootstrapAdministrationService::class)->currentAdministrators($school)));

        // The Manager configures modules, but never activates.
        $this->change($manager, $school, 'communications', 'enable', ['reason_code' => 'onboarding_configuration'])->assertSessionHasNoErrors();
        $this->assertTrue($this->state($school, 'communications'));
        $this->lifecycleAction($manager, $school, 'activate')->assertForbidden();

        // Root activates: no optional module is required.
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors();
        $this->assertTrue($school->fresh()->isActive());
        $this->assertSame([...ModuleCatalogue::coreKeys(), 'communications'], app(SchoolModuleEntitlements::class)->entitledModules($school));

        // The enabled module works for the School Administrator; a disabled one does not.
        $capabilities = app(CapabilityResolver::class)->schoolCapabilities($admin, $school->fresh());
        $this->assertContains('communications.view', $capabilities);
        $this->assertNotContains('integrations.api_clients.view', $capabilities);
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");
        $this->withSession(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get('/app/communications')->assertOk();
        $this->get('/app/integrations/api-clients')->assertForbidden();

        // The Manager holds no School authority.
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($manager, $school->fresh()));
    }

    #[Test]
    public function a_school_created_by_root_gets_the_same_core_only_default(): void
    {
        $root = $this->platformAdmin();
        $school = $this->provisionSchool($root, $this->createUser());

        $this->assertSame(ModuleCatalogue::coreKeys(), app(SchoolModuleEntitlements::class)->entitledModules($school));
        $this->assertSame(array_fill_keys(ModuleCatalogue::optionalKeys(), false), app(SchoolModuleEntitlements::class)->optionalStates($school));
    }

    #[Test]
    public function an_existing_school_keeps_every_optional_module(): void
    {
        $school = $this->createSchool();

        $this->assertEqualsCanonicalizing(ModuleCatalogue::keys(), app(SchoolModuleEntitlements::class)->entitledModules($school));
    }
}
