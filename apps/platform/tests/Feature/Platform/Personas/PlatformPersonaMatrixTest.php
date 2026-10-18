<?php

namespace Tests\Feature\Platform\Personas;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleAudit;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformRoleAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\TestCase;

/**
 * ORG.4 (ADR 0076 §6-§8, §30): the three platform personas.
 *
 *   Platform Admin (root)  -- registry, onboarding, lifecycle, role governance, audit
 *   Platform Manager       -- registry + Groups (read), onboarding only
 *   Platform Auditor       -- registry + Groups (read), audit review; changes nothing
 *
 * Onboarding (create into `provisioning`, the bootstrap administrator while
 * provisioning) never implies lifecycle control, and no platform persona is
 * ever School authority. Proved through HTTP AND the services directly.
 */
class PlatformPersonaMatrixTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    private function manager(User $root): User
    {
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $this->withFactor($manager);

        return $manager;
    }

    private function auditor(User $root): User
    {
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        $this->withFactor($auditor);

        return $auditor;
    }

    private function withFactor(User $user): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);
        $this->mfaSecrets[$user->id] = $secret;
    }

    private function request(?string $code = null): Request
    {
        $request = Request::create('/app/platform/schools', 'POST', $code === null ? [] : ['mfa_code' => $code]);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function denials(User $actor): array
    {
        return PlatformAuditEvent::query()->where('actor_user_id', $actor->id)->where('event_type', SchoolLifecycleAudit::DENIED)
            ->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->map(fn ($m) => $m['outcome_code'].':'.$m['operation'])->all();
    }

    // -- The matrix over HTTP ----------------------------------------------------

    #[Test]
    public function the_platform_manager_onboards_and_never_controls_the_lifecycle(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $admin = $this->createUser();

        // Registry and onboarding pages.
        $this->actingAs($manager)->get('/app/platform/schools')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canCreate', true));
        $this->actingAs($manager)->get('/app/platform/schools/create')->assertOk();

        // Create: a provisioning School, whatever lifecycle field is sent.
        $school = $this->provisionSchool($manager, $admin, ['name' => 'Manager School', 'status' => 'active', 'closed_at' => now()->toIso8601String()]);
        $this->assertSame(SchoolStatus::Provisioning->value, $school->status);
        $this->assertNull($school->closed_at);
        $this->assertSame($manager->id, $this->lifecycleEvents($school, SchoolLifecycleAudit::CREATED)[0]->actor_user_id);

        // The overview: the bootstrap administrator yes, activation no.
        $this->actingAs($manager)->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canManage', false)->where('canOnboard', true)
            ->where('actions.replaceBootstrapAdmin', true)->where('actions.activate', false)
            ->where('audit.reason', 'capability'));
        $this->actingAs($manager)->get("/app/platform/schools/{$school->id}/bootstrap-admin")->assertOk();
        $replacement = $this->createUser();
        $this->lifecycleAction($manager, $school, 'bootstrap-admin', ['admin' => $replacement->email])->assertSessionHasNoErrors();
        $this->assertSame([$replacement->id], array_map(fn ($m) => $m->user_id, app(SchoolBootstrapAdministrationService::class)->currentAdministrators($school)));

        // Every lifecycle page and change is refused -- even for its own School.
        foreach (['activate', 'suspend', 'resume', 'close', 'reopen'] as $action) {
            $this->actingAs($manager)->get("/app/platform/schools/{$school->id}/{$action}")->assertForbidden();
        }
        $this->lifecycleAction($manager, $school, 'activate')->assertForbidden();
        $this->assertSame(SchoolStatus::Provisioning->value, $school->fresh()->status);

        // Root activates it; the Manager can no longer touch its bootstrap administrator or status.
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors();
        $this->assertTrue($school->fresh()->isActive());
        foreach (['suspend' => ['reason_code' => 'administrative_hold'], 'close' => ['reason_code' => 'contract_ended']] as $action => $extra) {
            $this->lifecycleAction($manager, $school, $action, $extra)->assertForbidden();
        }
        $this->lifecycleAction($manager, $school, 'bootstrap-admin', ['admin' => $this->createUser()->email])->assertSessionHasErrors('school');
        $this->assertTrue($school->fresh()->isActive());

        $this->assertSame([
            'capability_missing:activate', 'capability_missing:suspend', 'capability_missing:close', 'bootstrap_closed:replace_bootstrap_admin',
        ], $this->denials($manager));
    }

    #[Test]
    public function the_platform_manager_has_no_other_platform_or_school_power(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $resolver = app(CapabilityResolver::class);

        $this->assertEqualsCanonicalizing(['platform.schools.view', 'platform.school_groups.view', 'platform.schools.onboard', 'platform.school_entitlements.view', 'platform.school_entitlements.manage'], $resolver->platformCapabilities($manager));
        $this->assertSame([], $resolver->schoolCapabilities($manager, $school));
        $this->assertSame([], $resolver->groupCapabilities($manager, $group));

        $this->actingAs($manager)->withSession(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('navigation', self::nav(shown: ['platform-schools', 'platform-groups'], hidden: ['platform-roles', 'platform-audit-log']))
            ->where('platformElevation.canStart', false));
        // No elevation, audit review, role or Group governance, School pages.
        $this->get('/app/platform/elevation')->assertForbidden();
        $this->post('/app/platform/elevation/confirm', ['target' => $school->id, 'reason_code' => 'operational_support'])->assertForbidden();
        $this->get('/app/platform/audit-log')->assertForbidden();
        $this->get('/app/platform/roles')->assertForbidden();
        $this->post('/app/platform/roles/grants', ['user' => $this->createUser()->email, 'role' => 'platform_auditor', 'mfa_code' => '123456'])->assertForbidden();
        $this->get('/app/platform/groups')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', false));
        $this->post('/app/platform/groups', ['name' => 'Manager Trust', 'slug' => 'manager-trust'])->assertForbidden();
        $this->post("/app/platform/groups/{$group->id}/grants", ['user' => $this->createUser()->email])->assertForbidden();
        $this->get('/app/students')->assertRedirect('/app');
    }

    #[Test]
    public function the_platform_auditor_reads_and_changes_nothing(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->auditor($root);
        $school = $this->provisionSchool($root, $this->createUser());

        $this->actingAs($auditor)->get('/app/platform/schools')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canCreate', false));
        $this->actingAs($auditor)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get("/app/platform/schools/{$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canManage', false)->where('canOnboard', false)->where('audit.available', true)
            ->where('actions.replaceBootstrapAdmin', false));
        $this->actingAs($auditor)->get('/app/platform/schools/create')->assertForbidden();
        $this->actingAs($auditor)->get("/app/platform/schools/{$school->id}/bootstrap-admin")->assertForbidden();

        $this->createSchoolViaPlatform($auditor, $this->createUser(), ['slug' => 'auditor-made'])->assertForbidden();
        $this->lifecycleAction($auditor, $school, 'bootstrap-admin', ['admin' => $this->createUser()->email])->assertForbidden();
        $this->lifecycleAction($auditor, $school, 'activate')->assertForbidden();
        $this->assertSame(0, School::query()->where('slug', 'auditor-made')->count());
        $this->assertTrue($school->fresh()->isProvisioning());
        $this->assertSame(['capability_missing:create', 'capability_missing:replace_bootstrap_admin', 'capability_missing:activate'], $this->denials($auditor));
    }

    #[Test]
    public function the_platform_admin_keeps_every_duty(): void
    {
        $root = $this->platformAdmin();
        $this->actingAs($root)->get('/app/platform/schools')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canCreate', true));
        $school = $this->activeSchoolViaPlatform($root, $this->createUser());
        $this->suspendViaPlatform($root, $school);
        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasNoErrors();
        $this->lifecycleAction($root, $school, 'close', ['reason_code' => 'contract_ended'])->assertSessionHasNoErrors();
        $this->lifecycleAction($root, $school, 'reopen')->assertSessionHasNoErrors();
        $this->assertTrue($school->fresh()->isActive());

        $governance = app(PlatformRoleGovernanceService::class);
        $governance->grant($this->request($this->issueRecoveryCodes($root, 1)[0]), $root, $this->createUser()->email, 'platform_manager');
        $governance->grant($this->request($this->issueRecoveryCodes($root, 1)[0]), $root, $this->createUser()->email, 'platform_auditor');
        $this->actingAs($root)->withSession(['mfa_verified_at' => now()->toIso8601String()])->get('/app/platform/audit-log')->assertOk();
    }

    #[Test]
    public function school_authority_and_the_unauthenticated_never_reach_platform_onboarding(): void
    {
        $school = $this->createSchool();
        $actors = [];
        foreach (['school_admin', 'principal', 'teacher', 'accountant'] as $role) {
            $user = $this->createUser();
            $this->assignSchoolRole($this->createMembership($user, $school), $role);
            $actors[$role] = $user;
        }
        $actors['guardian'] = $this->portalGuardian($school)['user'];

        foreach ($actors as $role => $actor) {
            $this->withFactor($actor);
            $this->actingAs($actor)->get('/app/platform/schools')->assertForbidden();
            $this->actingAs($actor)->get('/app/platform/schools/create')->assertForbidden();
            $this->createSchoolViaPlatform($actor, $this->createUser(), ['slug' => "made-by-{$role}"])->assertForbidden();
            $this->assertSame([], app(CapabilityResolver::class)->platformCapabilities($actor), $role);
        }
        $this->assertSame(0, School::query()->where('slug', 'like', 'made-by-%')->count());

        $this->app['auth']->forgetGuards();
        foreach (['/app/platform/schools', '/app/platform/schools/create', '/app/platform/roles', '/app/platform/groups'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // -- Services directly --------------------------------------------------------

    #[Test]
    public function the_services_apply_the_same_rule_when_called_directly(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $auditor = $this->auditor($root);
        [$schoolAdmin] = $this->createSchoolAdmin();
        $this->withFactor($schoolAdmin);
        $lifecycle = app(SchoolLifecycleService::class);
        $input = fn (string $slug) => ['name' => 'Direct', 'slug' => $slug, 'admin' => $this->createUser()->email];

        $school = $lifecycle->create($this->request(), $manager, $input('direct-manager'), '1', $this->totpFor($manager));
        $this->assertTrue($school->isProvisioning());

        foreach ([$auditor, $schoolAdmin] as $actor) {
            $this->assertRefused(fn () => $lifecycle->create($this->request(), $actor, $input('direct-'.Str::lower(Str::random(6))), '1', $this->totpFor($actor)));
        }
        $code = $this->issueRecoveryCodes($manager, 1)[0];
        foreach ([
            fn () => $lifecycle->activate($this->request(), $manager, $school, '1', $code),
            fn () => $lifecycle->suspend($this->request(), $manager, $school, 'administrative_hold', '1', $code),
            fn () => $lifecycle->resume($this->request(), $manager, $school, '1', $code),
            fn () => $lifecycle->close($this->request(), $manager, $school, 'contract_ended', '1', $code),
            fn () => $lifecycle->reopen($this->request(), $manager, $school, '1', $code),
        ] as $operation) {
            $this->assertRefused($operation);
        }
        $this->assertTrue($school->fresh()->isProvisioning());

        // MFA order: none of those refusals spent the Manager's code.
        $second = $lifecycle->create($this->request(), $manager, $input('direct-manager-2'), '1', $code);
        $this->assertTrue($second->isProvisioning());
    }

    #[Test]
    public function onboarding_needs_a_fresh_code_and_never_duplicates_a_school(): void
    {
        $root = $this->platformAdmin();
        $manager = $this->manager($root);
        $admin = $this->createUser();

        $this->createSchoolViaPlatform($manager, $admin, ['slug' => 'no-code-school', 'mfa_code' => ''])->assertSessionHasErrors('mfa_code');
        $this->createSchoolViaPlatform($manager, $admin, ['slug' => 'bad-code-school', 'mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->assertSame(0, School::query()->whereIn('slug', ['no-code-school', 'bad-code-school'])->count());

        $this->provisionSchool($manager, $admin, ['slug' => 'once-only', 'code' => 'ONCE']);
        $this->createSchoolViaPlatform($manager, $this->createUser(), ['slug' => 'once-only'])->assertSessionHasErrors('slug');
        $this->createSchoolViaPlatform($manager, $this->createUser(), ['slug' => 'once-again', 'code' => 'ONCE'])->assertSessionHasErrors('code');
        $this->createSchoolViaPlatform($manager, $manager)->assertSessionHasErrors('admin');
        $this->assertSame(1, School::query()->where('slug', 'like', 'once-%')->count());
    }

    // -- Manager / Auditor separation --------------------------------------------

    #[Test]
    public function one_person_never_holds_the_manager_and_the_auditor(): void
    {
        $root = $this->platformAdmin();
        $governance = app(PlatformRoleGovernanceService::class);
        $grant = fn (User $target, string $role, string $code) => $governance->grant($this->request($code), $root, $target->email, $role);

        $person = $this->createUser();
        $managerGrant = $grant($person, 'platform_manager', $this->issueRecoveryCodes($root, 1)[0]);

        // Refused before the code is spent, and audited.
        $code = $this->issueRecoveryCodes($root, 1)[0];
        $this->assertValidationRefused(fn () => $grant($person, 'platform_auditor', $code), 'user');
        $other = $this->createUser();
        $grant($other, 'platform_auditor', $code);

        $this->assertValidationRefused(fn () => $grant($other, 'platform_manager', $this->issueRecoveryCodes($root, 1)[0]), 'user');
        $outcomes = PlatformAuditEvent::query()->where('event_type', 'platform.role_grant.denied')->pluck('metadata')->pluck('outcome_code')->all();
        $this->assertSame(['role_conflict', 'role_conflict'], $outcomes);

        // The database refuses it too, whatever the path.
        $this->assertDatabaseRefuses(fn () => PlatformRoleAssignment::query()->create([
            'user_id' => $person->id, 'role_id' => $this->roleId('platform_auditor'), 'granted_by_user_id' => $root->id, 'granted_at' => now(),
        ]));
        $this->assertDatabaseRefuses(fn () => DB::table('platform_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $other->id, 'role_id' => $this->roleId('platform_manager'),
            'granted_by_user_id' => $root->id, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        // Revoking one makes the other grantable.
        $governance->revoke($this->request($this->issueRecoveryCodes($root, 1)[0]), $root, $managerGrant);
        $grant($person, 'platform_auditor', $this->issueRecoveryCodes($root, 1)[0]);
        $this->assertContains('platform.audit.view', app(CapabilityResolver::class)->platformCapabilities($person->fresh()));
    }

    #[Test]
    public function the_onboarding_capability_never_reaches_a_school_or_group_role(): void
    {
        foreach (['school_admin', 'group_admin'] as $role) {
            foreach (['platform.schools.onboard', 'platform.school_entitlements.manage'] as $capability) {
                $this->assertDatabaseRefuses(fn () => DB::connection('pgsql_admin')->table('role_capabilities')->insert([
                    'role_id' => $this->roleId($role), 'capability_key' => $capability,
                ]));
            }
        }
        $this->assertSame('platform', DB::table('capabilities')->where('key', 'platform.schools.onboard')->value('namespace'));
    }

    private function roleId(string $key): string
    {
        return (string) DB::table('roles')->where('key', $key)->value('id');
    }

    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The operation must be refused.');
        } catch (SchoolLifecycleDeniedException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function assertValidationRefused(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('The grant must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The database must refuse this write.');
        } catch (QueryException $e) {
            $this->assertTrue(true, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
