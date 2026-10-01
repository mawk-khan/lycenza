<?php

namespace Tests\Feature\Platform\Schools;

use App\Domain\Identity\Application\Staff\BootstrapAccountProvisioningService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleAudit;
use App\Domain\Platform\Application\Schools\SchoolLifecycleAuthority;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.9 (ADR 0047 sections 2-7, 10, 13): CREATE with the bootstrap
 * School Administrator, bootstrap replacement while provisioning,
 * ACTIVATE, SUSPEND, RESUME -- each through the real HTTP surface with a
 * fresh MFA code and explicit confirmation, audited on the platform
 * ledger only, and every security/state refusal audited once.
 */
class SchoolLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    private function roles(School $school, SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)
            ->with('role')->get()
            ->map(fn (MembershipRoleAssignment $a) => $a->role->key)->all());
    }

    #[Test]
    public function the_root_creates_a_provisioning_school_with_a_real_bootstrap_administrator(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser(['email' => 'first.admin@example.test']);

        $response = $this->createSchoolViaPlatform($root, 'FIRST.ADMIN@example.test', ['name' => 'Riverside', 'slug' => 'riverside', 'code' => 'rvs']);

        $school = School::query()->where('slug', 'riverside')->firstOrFail();
        $response->assertRedirect("/app/platform/schools/{$school->id}");
        $this->assertSame('provisioning', $school->status);
        $this->assertSame('RVS', $school->code);
        $this->assertFalse($school->isActive());

        // A real, ordinary membership with the ordinary School Admin role.
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertSame(['school_admin'], $this->roles($school, $membership));
        $assignment = app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->firstOrFail());
        $this->assertSame($root->id, $assignment->assigned_by_user_id);
        $held = app(CapabilityResolver::class)->schoolCapabilities($admin, $school);
        $this->assertContains('school.members.manage', $held);
        $this->assertContains('school.roles.manage', $held);

        // The creator gets nothing; nothing else is created.
        $this->assertSame(1, SchoolMembership::query()->where('school_id', $school->id)->count());
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $root->id)->count());
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($root, $school));
        $this->assertSame(0, DB::table('school_group_members')->where('school_id', $school->id)->count());
        $this->assertSame(0, SchoolElevation::query()->where('school_id', $school->id)->count());
        $this->assertSame(0, DB::table('campuses')->where('school_id', $school->id)->count());
        $this->assertSame(0, DB::table('domain_event_outbox')->where('school_id', $school->id)->count());
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->count()));

        $created = $this->lifecycleEvents($school, SchoolLifecycleAudit::CREATED);
        $this->assertCount(1, $created);
        $this->assertEquals(['status' => 'provisioning'], $created[0]->metadata);
        $this->assertSame($root->id, $created[0]->actor_user_id);
        $assigned = $this->lifecycleEvents($school, SchoolLifecycleAudit::BOOTSTRAP_ADMIN_ASSIGNED);
        $this->assertEquals(['user_id' => $admin->id, 'membership_id' => $membership->id], $assigned[0]->metadata);
    }

    #[Test]
    public function creation_input_errors_are_plain_validation_and_security_refusals_are_audited(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser();
        $disabled = $this->createUser(['is_disabled' => true]);
        $taken = $this->createSchool(['slug' => 'taken-slug']);

        foreach ([
            [['admin' => 'nobody@example.test'], 'admin'],
            [['admin' => $disabled->email], 'admin'],
            [['slug' => 'Not A Slug'], 'slug'],
            [['slug' => $taken->slug], 'slug'],
            [['name' => ''], 'name'],
            [['confirmed' => ''], 'confirmed'],
            [['mfa_code' => ''], 'mfa_code'],
        ] as [$overrides, $field]) {
            $this->createSchoolViaPlatform($root, $admin, $overrides)->assertSessionHasErrors($field);
        }
        $this->assertSame([], $this->lifecycleDenials($root), 'Input validation is not an escalation signal.');

        $this->createSchoolViaPlatform($root, $root)->assertSessionHasErrors('admin');
        $this->createSchoolViaPlatform($root, $admin, ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');

        $this->assertEquals([
            ['operation' => 'create', 'outcome_code' => 'self_nomination'],
            ['operation' => 'create', 'outcome_code' => 'mfa_verification_failed'],
        ], $this->lifecycleDenials($root));
        $this->assertSame(0, School::query()->where('slug', 'like', 'lifecycle-%')->count());
    }

    #[Test]
    public function only_platform_schools_manage_reaches_the_surface_and_every_refusal_is_audited(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor');
        [$schoolAdmin] = $this->createSchoolAdmin('school_admin');
        $target = $this->createUser();

        foreach ([$auditor, $this->groupAdmin($group), $schoolAdmin, $this->createUser()] as $actor) {
            if (! $actor->mfaFactors()->where('status', 'active')->exists()) {
                $this->enrollActiveMfaFactor($actor);
            }
            $this->actingAs($actor);
            $this->get('/app/platform/schools')->assertForbidden();
            $this->get('/app/platform/schools/create')->assertForbidden();
            $this->get("/app/platform/schools/{$school->id}")->assertForbidden();
            $this->get("/app/platform/schools/{$school->id}/suspend")->assertForbidden();
            $this->post('/app/platform/schools', ['name' => 'X', 'slug' => 'x-school', 'admin' => $target->email, 'confirmed' => '1', 'mfa_code' => '123456'])->assertForbidden();
            $this->post("/app/platform/schools/{$school->id}/suspend", ['reason_code' => 'security_incident', 'confirmed' => '1', 'mfa_code' => '123456'])->assertForbidden();

            $this->assertEquals([
                ['operation' => 'create', 'outcome_code' => 'capability_missing'],
                ['operation' => 'suspend', 'outcome_code' => 'capability_missing'],
            ], $this->lifecycleDenials($actor));
        }

        $this->assertSame('active', $school->fresh()->status);
        $this->assertSame(0, School::query()->where('slug', 'x-school')->count());
    }

    #[Test]
    public function a_root_without_a_factor_or_with_a_wrong_code_changes_nothing(): void
    {
        $noFactor = $this->platformAdmin(withMfa: false);
        $school = $this->createSchool();

        $this->actingAs($noFactor)->get("/app/platform/schools/{$school->id}/suspend")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/Schools/Action')->where('mfaEnrolled', false));
        $this->post("/app/platform/schools/{$school->id}/suspend", ['reason_code' => 'security_incident', 'confirmed' => '1', 'mfa_code' => '123456'])
            ->assertSessionHasErrors(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);
        $this->assertEquals([['operation' => 'suspend', 'outcome_code' => 'mfa_not_enrolled']], $this->lifecycleDenials($noFactor));

        $root = $this->platformAdmin();
        $this->lifecycleAction($root, $school, 'suspend', ['reason_code' => 'security_incident', 'mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->assertEquals([['operation' => 'suspend', 'outcome_code' => 'mfa_verification_failed']], $this->lifecycleDenials($root));
        $this->assertSame('active', $school->fresh()->status);
    }

    #[Test]
    public function a_provisioning_school_is_not_operational_for_anyone(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser();
        $school = $this->provisionSchool($root, $admin);

        // The bootstrap administrator cannot select it or reach it by API.
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');
        $this->assertNull(session('active_school_id'));
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('memberships.0.available', false)
            ->where('activeSchool', null));
        $token = $admin->createToken('device')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}/context")->assertNotFound();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}")->assertNotFound();

        // Nobody elevates into it.
        $this->startElevation($root, $school)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('errors.target', 'That School cannot be entered.'));
        $this->assertDenied($root, 'target_inactive');
        $this->assertSame(0, SchoolElevation::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function activation_needs_an_administrator_who_has_activated_their_account(): void
    {
        // Phase 0O.12B (ADR 0059 section 7.3): a credential-less bootstrap
        // account (operator-provisioned, not yet activated) is a valid
        // bootstrap target but not a qualifying administrator.
        $root = $this->platformAdmin();
        $issued = app(BootstrapAccountProvisioningService::class)->provision('pending.admin@example.test', 'Pending Admin', null);
        $school = $this->provisionSchool($root, $issued->user);

        $this->lifecycleAction($root, $school, 'activate')
            ->assertSessionHasErrors(['school' => 'The School\'s administrator has not activated their account yet. They must set their password with their activation link first.']);
        $this->assertSame('provisioning', $school->fresh()->status);
        $this->assertEquals([['operation' => 'activate', 'outcome_code' => 'admin_not_activated']], $this->lifecycleDenials($root));

        preg_match('#/account-activation/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $issued->link, $m);
        $this->post("http://localhost/account-activation/{$m[1]}", ['secret' => $m[2], 'password' => 'pending-admin-password-1', 'password_confirmation' => 'pending-admin-password-1'])->assertRedirect();

        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors();
        $this->assertSame('active', $school->fresh()->status);
    }

    #[Test]
    public function activation_needs_a_qualifying_administrator_and_then_opens_the_school(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser();
        $school = $this->provisionSchool($root, $admin);

        // The only administrator is disabled: activation is refused.
        $admin->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->get("/app/platform/schools/{$school->id}")->assertOk();
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasErrors('school');
        $this->assertSame('provisioning', $school->fresh()->status);
        $this->assertEquals([['operation' => 'activate', 'outcome_code' => 'admin_missing']], $this->lifecycleDenials($root));

        $admin->forceFill(['is_disabled' => false, 'disabled_at' => null])->save();
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors()->assertRedirect("/app/platform/schools/{$school->id}");
        $this->assertSame('active', $school->fresh()->status);
        $this->assertEquals(['from' => 'provisioning', 'to' => 'active'], $this->lifecycleEvents($school, SchoolLifecycleAudit::ACTIVATED)[0]->metadata);

        // Activating twice is an invalid transition, not a repeat.
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasErrors('school');
        $this->assertSame('invalid_transition', $this->lifecycleDenials($root)[1]['outcome_code']);
        $this->assertCount(1, $this->lifecycleEvents($school, SchoolLifecycleAudit::ACTIVATED));

        // The bootstrap administrator now uses the School normally.
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->assertSame($school->id, session('active_school_id'));
        $this->get('/app/settings')->assertOk();
    }

    #[Test]
    public function a_capability_holder_other_than_school_admin_also_qualifies_but_a_role_name_alone_does_not(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool(['status' => 'provisioning']);

        // A custom role holding only ONE of the two capabilities: not enough.
        $this->createUserWithCapabilities($school, ['school.members.manage']);
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasErrors('school');

        // Holding both, through any role: qualifies (capability test).
        $this->createUserWithCapabilities($school, ['school.members.manage', 'school.roles.manage']);
        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors();
        $this->assertSame('active', $school->fresh()->status);
    }

    #[Test]
    public function the_bootstrap_administrator_is_replaced_before_activation_keeping_history(): void
    {
        $root = $this->platformAdmin();
        $first = $this->createUser();
        $second = $this->createUser();
        $school = $this->provisionSchool($root, $first);
        $firstMembership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $first->id)->firstOrFail();

        $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('bootstrapAdmins.0.email', $first->email)
            ->where('actions.replaceBootstrapAdmin', true));

        // The current administrator again, or yourself: refused and audited.
        $this->lifecycleAction($root, $school, 'bootstrap-admin', ['admin' => $first->email])->assertSessionHasErrors('admin');
        $this->lifecycleAction($root, $school, 'bootstrap-admin', ['admin' => $root->email])->assertSessionHasErrors('admin');

        $this->lifecycleAction($root, $school, 'bootstrap-admin', ['admin' => $second->id])->assertSessionHasNoErrors()->assertRedirect();

        $firstMembership->refresh();
        $this->assertSame('suspended', $firstMembership->status, 'Ended, not deleted.');
        $this->assertSame(['school_admin'], $this->roles($school, $firstMembership), 'Its role assignment stays as history.');
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($first, $school));
        $secondMembership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $second->id)->firstOrFail();
        $this->assertSame('active', $secondMembership->status);
        $this->assertSame(['school_admin'], $this->roles($school, $secondMembership));

        $replaced = $this->lifecycleEvents($school, SchoolLifecycleAudit::BOOTSTRAP_ADMIN_REPLACED);
        $this->assertEquals(['previous_user_id' => $first->id, 'user_id' => $second->id, 'membership_id' => $secondMembership->id], $replaced[0]->metadata);
        $this->assertEquals([
            ['operation' => 'replace_bootstrap_admin', 'outcome_code' => 'bootstrap_target_conflict'],
            ['operation' => 'replace_bootstrap_admin', 'outcome_code' => 'self_nomination'],
        ], $this->lifecycleDenials($root));

        // Going back to the first person reactivates their one membership row.
        $this->lifecycleAction($root, $school, 'bootstrap-admin', ['admin' => $first->email])->assertSessionHasNoErrors();
        $this->assertSame('active', $firstMembership->fresh()->status);
        $this->assertSame('suspended', $secondMembership->fresh()->status);
        $this->assertSame(2, SchoolMembership::query()->where('school_id', $school->id)->count());
        // Phase 0O.12B: the earlier grant was revoked when the membership was
        // suspended and stays as history; returning grants a NEW row.
        $this->assertSame(2, app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_membership_id', $firstMembership->id)->count()));
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_membership_id', $firstMembership->id)->active()->count()));
    }

    #[Test]
    public function the_bootstrap_path_closes_for_good_at_first_activation(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser();
        $other = $this->createUser();
        $school = $this->activeSchoolViaPlatform($root, $admin);

        $closed = function () use ($root, $school, $other): void {
            $this->actingAs($root)->get("/app/platform/schools/{$school->id}")->assertInertia(fn (AssertableInertia $p) => $p
                ->where('bootstrapAdmins', [])
                ->where('actions.replaceBootstrapAdmin', false)
                ->where('actions.activate', false));
            $this->lifecycleAction($root, $school, 'bootstrap-admin', ['admin' => $other->email])->assertSessionHasErrors('school');
            $this->assertSame(0, SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $other->id)->count());
        };

        $closed();

        // Still closed after suspend and resume.
        $this->suspendViaPlatform($root, $school);
        $closed();
        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasNoErrors();
        $closed();

        // Still closed when the School loses every administrator: that is
        // a future break-glass decision, never this path.
        SchoolMembership::query()->where('school_id', $school->id)->update(['status' => 'suspended']);
        $this->assertTrue(app(SchoolLifecycleAuthority::class)->qualifyingAdministrators($school->fresh())->isEmpty());
        $closed();

        $outcomes = array_column($this->lifecycleDenials($root), 'outcome_code');
        $this->assertSame(['bootstrap_closed', 'bootstrap_closed', 'bootstrap_closed', 'bootstrap_closed'], $outcomes);
    }

    #[Test]
    public function suspension_ends_elevations_and_deletes_nothing_and_resume_restores_nothing(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser();
        $school = $this->activeSchoolViaPlatform($root, $admin);
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group);
        $operator = $this->platformAdmin();
        $platformElevation = $this->elevate($operator, $school);
        $groupElevation = $this->elevateViaGroup($groupAdmin, $group, $school);

        $this->lifecycleAction($root, $school, 'suspend')->assertSessionHasErrors('reason_code');
        $this->lifecycleAction($root, $school, 'suspend', ['reason_code' => 'because'])->assertSessionHasErrors('reason_code');
        $this->assertSame('active', $school->fresh()->status);

        $this->suspendViaPlatform($root, $school, 'security_incident');

        $this->assertSame('suspended', $school->fresh()->status);
        $this->assertEquals(['from' => 'active', 'to' => 'suspended', 'reason_code' => 'security_incident'], $this->lifecycleEvents($school, SchoolLifecycleAudit::SUSPENDED)[0]->metadata);
        foreach ([$platformElevation, $groupElevation] as $elevation) {
            $elevation->refresh();
            $this->assertSame('terminated', $elevation->status);
            $this->assertSame('school_suspended', $elevation->end_reason);
        }

        // Nothing removed.
        $this->assertSame('active', SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $admin->id)->value('status'));
        $this->assertSame(1, DB::table('school_group_members')->where('school_id', $school->id)->count());

        // Suspending again is an invalid transition.
        $this->lifecycleAction($root, $school, 'suspend', ['reason_code' => 'school_requested'])->assertSessionHasErrors('school');
        $this->assertCount(1, $this->lifecycleEvents($school, SchoolLifecycleAudit::SUSPENDED));

        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasNoErrors();
        $this->assertSame('active', $school->fresh()->status);
        $this->assertEquals(['from' => 'suspended', 'to' => 'active'], $this->lifecycleEvents($school, SchoolLifecycleAudit::RESUMED)[0]->metadata);
        $this->assertSame('terminated', $platformElevation->fresh()->status, 'No elevation is restored.');
        $this->assertSame(0, SchoolElevation::query()->where('school_id', $school->id)->where('status', 'active')->count());

        // Resuming an active School is refused.
        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasErrors('school');

        // Lifecycle evidence stays on the platform ledger only.
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'platform.school.%')->count()));
    }

    #[Test]
    public function the_pages_show_platform_metadata_only_and_offer_no_archive_delete_or_membership_administration(): void
    {
        $root = $this->platformAdmin();
        $admin = $this->createUser(['email' => 'page.admin@example.test']);
        $school = $this->provisionSchool($root, $admin, ['name' => 'Page School']);

        $index = $this->actingAs($root)->get('/app/platform/schools');
        $index->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/Schools/Index')->has('schools'));
        $row = collect($index->viewData('page')['props']['schools'])->firstWhere('id', $school->id);
        $this->assertSame(['id', 'name', 'slug', 'code', 'status', 'closedAt', 'closureReason'], array_keys($row), 'platform lifecycle metadata only (E21.2F adds the closure marker)');

        $this->get("/app/platform/schools/{$school->id}/activate")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/Schools/Action')->where('targetStatus', 'active'));
        $this->get("/app/platform/schools/{$school->id}/archive")->assertNotFound();
        $this->get("/app/platform/schools/{$school->id}/delete")->assertNotFound();
        $this->post("/app/platform/schools/{$school->id}/archive", ['confirmed' => '1'])->assertNotFound();
        $this->delete("/app/platform/schools/{$school->id}")->assertStatus(405);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platform.canManageSchools', true));
    }

    #[Test]
    public function the_platform_auditor_sees_lifecycle_events_as_envelopes_and_can_change_nothing(): void
    {
        $root = $this->platformAdmin();
        $school = $this->activeSchoolViaPlatform($root, $this->createUser());
        $auditor = $this->createUser();
        PlatformRoleAssignment::query()->create([
            'user_id' => $auditor->id,
            'role_id' => DB::table('roles')->where('key', 'platform_auditor')->value('id'),
            'granted_by_user_id' => $root->id,
            'granted_at' => now(),
        ]);
        $this->enrollActiveMfaFactor($auditor);

        $log = $this->actingAs($auditor)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get('/app/platform/audit-log')->assertOk()->viewData('page')['props']['log'];
        $types = array_column($log['entries'], 'eventType');
        foreach ([SchoolLifecycleAudit::CREATED, SchoolLifecycleAudit::BOOTSTRAP_ADMIN_ASSIGNED, SchoolLifecycleAudit::ACTIVATED] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertStringNotContainsString('"from"', json_encode($log));
        $this->assertStringNotContainsString('membership_id', json_encode($log));

        $this->get('/app/platform/schools')->assertForbidden();
        $this->post("/app/platform/schools/{$school->id}/suspend", ['reason_code' => 'security_incident', 'confirmed' => '1', 'mfa_code' => '123456'])->assertForbidden();
        $this->assertSame('active', $school->fresh()->status);
    }

    #[Test]
    public function every_change_is_rate_limited_per_actor(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();
        $this->resetLifecycleLimiter($root);
        $this->actingAs($root);

        for ($i = 0; $i < 8; $i++) {
            $this->post("/app/platform/schools/{$school->id}/resume", ['confirmed' => '1', 'mfa_code' => '1'])->assertSessionHasErrors('school');
        }
        $this->post("/app/platform/schools/{$school->id}/resume", ['confirmed' => '1', 'mfa_code' => '1'])->assertStatus(429);
    }

    #[Test]
    public function a_disabled_operator_is_refused_at_the_service_and_audited(): void
    {
        $root = $this->platformAdmin();
        $school = $this->createSchool();
        $root->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        try {
            app(SchoolLifecycleService::class)->suspend(request(), $root->fresh(), $school, 'security_incident', true, '123456');
            $this->fail('A disabled operator must be refused.');
        } catch (SchoolLifecycleDeniedException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertEquals([['operation' => 'suspend', 'outcome_code' => 'actor_disabled']], $this->lifecycleDenials($root));
        $this->assertSame('active', $school->fresh()->status);
    }

    #[Test]
    public function the_dashboard_lists_a_suspended_membership_as_unavailable_and_never_why(): void
    {
        $root = $this->platformAdmin();
        $member = $this->createUser();
        $open = $this->createSchool(['name' => 'Open School']);
        $this->createMembership($member, $open);
        $school = $this->activeSchoolViaPlatform($root, $member);
        $this->suspendViaPlatform($root, $school, 'security_incident');

        $page = $this->actingAs($member)->get('/app')->assertOk();
        $memberships = collect($page->viewData('page')['props']['memberships'])->keyBy('schoolId');
        $this->assertFalse($memberships[$school->id]['available']);
        $this->assertTrue($memberships[$open->id]['available']);
        $this->assertStringNotContainsString('security_incident', $page->getContent());
        $this->assertStringNotContainsString('suspended', json_encode($page->viewData('page')['props']['memberships']));
    }

    #[Test]
    public function a_user_is_never_created_and_no_other_platform_membership_operation_exists(): void
    {
        $root = $this->platformAdmin();
        $before = User::query()->count();

        $this->createSchoolViaPlatform($root, 'brand.new.person@example.test')->assertSessionHasErrors('admin');

        $this->assertSame($before, User::query()->count());
        $this->assertSame(0, User::query()->where('email', 'brand.new.person@example.test')->count());
    }
}
