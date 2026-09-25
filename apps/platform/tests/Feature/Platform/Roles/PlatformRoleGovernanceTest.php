<?php

namespace Tests\Feature\Platform\Roles;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0N.7 (ADR 0046 sections 3, 4, 6): runtime platform-role governance
 * -- only `platform_auditor`, only by a holder of the root-reserved
 * `platform.role_grants.manage`, never to or by oneself, never the root
 * role, history kept, every refusal audited.
 */
class PlatformRoleGovernanceTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function governance(): PlatformRoleGovernanceService
    {
        return app(PlatformRoleGovernanceService::class);
    }

    private function denials(User $actor): array
    {
        return PlatformAuditEvent::query()->where('actor_user_id', $actor->id)->where('event_type', 'platform.role_grant.denied')
            ->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->all();
    }

    #[Test]
    public function the_root_grants_and_revokes_platform_auditor_with_history_audit_and_immediate_effect(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser(['email' => 'auditor.person@example.test']);
        $resolver = app(CapabilityResolver::class);
        $this->assertSame([], $resolver->platformCapabilities($person));

        $grant = $this->governance()->grant($root, 'AUDITOR.PERSON@example.test', 'platform_auditor');

        $this->assertSame($root->id, $grant->granted_by_user_id);
        $this->assertSame(['platform.audit.view'], $resolver->platformCapabilities($person), 'The grant takes effect at once (cache forgotten).');
        $granted = PlatformAuditEvent::query()->where('event_type', 'platform.role_grant.granted')->firstOrFail();
        $this->assertEquals(['user_id' => $person->id, 'role_key' => 'platform_auditor'], $granted->metadata);
        $this->assertSame($grant->id, $granted->subject_id);
        $this->assertSame($root->id, $granted->actor_user_id);

        $this->governance()->revoke($root, $grant);

        $grant->refresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($root->id, $grant->revoked_by_user_id);
        $this->assertSame([], $resolver->platformCapabilities($person), 'The revocation takes effect at once.');
        $revoked = PlatformAuditEvent::query()->where('event_type', 'platform.role_grant.revoked')->firstOrFail();
        $this->assertEquals(['user_id' => $person->id, 'role_key' => 'platform_auditor'], $revoked->metadata);

        // Re-granting is a new row; the old one is history.
        $again = $this->governance()->grant($root, $person->id, 'platform_auditor');
        $this->assertNotSame($grant->id, $again->id);
        $this->assertSame(2, PlatformRoleAssignment::query()->where('user_id', $person->id)->count());
        $this->assertSame(1, PlatformRoleAssignment::query()->where('user_id', $person->id)->active()->count());

        try {
            $this->governance()->revoke($root, $grant->fresh());
            $this->fail('A revoked grant cannot be revoked again.');
        } catch (ValidationException) {
        }
    }

    #[Test]
    public function the_root_role_is_never_granted_or_revoked_and_the_refusal_is_audited(): void
    {
        $root = $this->platformAdmin();
        $other = $this->createUser();
        $otherRoot = $this->platformAdmin();

        foreach (['platform_super_admin', 'group_admin', 'school_admin', 'no_such_role'] as $key) {
            try {
                $this->governance()->grant($root, $other->email, $key);
                $this->fail("{$key} must not be grantable at runtime");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('role', $e->errors());
            }
        }

        $rootAssignment = PlatformRoleAssignment::query()->where('user_id', $otherRoot->id)->firstOrFail();
        try {
            $this->governance()->revoke($root, $rootAssignment);
            $this->fail('The root role must not be revocable at runtime.');
        } catch (ValidationException) {
        }
        $this->assertNull($rootAssignment->fresh()->revoked_at);
        $this->assertTrue(app(CapabilityResolver::class)->canPlatform($otherRoot, 'platform.schools.elevate'));
        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $other->id)->count());

        $denials = $this->denials($root);
        $this->assertCount(5, $denials);
        $this->assertEquals(['outcome_code' => 'role_not_assignable', 'role_key' => 'platform_super_admin'], $denials[0]);
        $this->assertEquals(['outcome_code' => 'role_not_assignable'], $denials[3], 'An unknown key is never copied into audit.');
    }

    #[Test]
    public function nobody_grants_or_revokes_their_own_platform_role(): void
    {
        $rootA = $this->platformAdmin();
        $rootB = $this->platformAdmin();

        try {
            $this->governance()->grant($rootA, $rootA->email, 'platform_auditor');
            $this->fail('Self-grant must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['You cannot grant a platform role to yourself.'], $e->errors()['user']);
        }

        // A second root grants A the auditor role; A may not revoke it.
        $grant = $this->governance()->grant($rootB, $rootA->email, 'platform_auditor');
        try {
            $this->governance()->revoke($rootA, $grant);
            $this->fail('Self-revoke must be refused.');
        } catch (ValidationException) {
        }
        $this->assertNull($grant->fresh()->revoked_at);

        $this->assertEquals(
            [['outcome_code' => 'self_grant', 'role_key' => 'platform_auditor'], ['outcome_code' => 'self_revoke', 'role_key' => 'platform_auditor']],
            $this->denials($rootA),
        );
    }

    #[Test]
    public function only_the_governance_capability_reaches_any_of_this_and_refusals_are_audited(): void
    {
        $root = $this->platformAdmin();
        $auditorUser = $this->createUser();
        $auditorGrant = $this->governance()->grant($root, $auditorUser->email, 'platform_auditor');
        $group = $this->createGroup([$this->createSchool()]);
        [$schoolAdmin] = $this->createSchoolAdmin('school_admin');
        $target = $this->createUser();

        foreach ([$auditorUser, $this->groupAdmin($group), $schoolAdmin] as $actor) {
            try {
                $this->governance()->grant($actor, $target->email, 'platform_auditor');
                $this->fail('Refused without platform.role_grants.manage');
            } catch (AccessDeniedHttpException) {
            }

            $this->actingAs($actor);
            $this->get('/app/platform/roles')->assertForbidden();
            $this->post('/app/platform/roles/grants', ['user' => $target->email])->assertForbidden();
            $this->post("/app/platform/roles/grants/{$auditorGrant->id}/revoke")->assertForbidden();

            $this->assertEquals(['outcome_code' => 'capability_missing', 'role_key' => 'platform_auditor'], $this->denials($actor)[0]);
        }

        $this->assertNull($auditorGrant->fresh()->revoked_at);
        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $target->id)->count());
    }

    #[Test]
    public function an_unknown_or_disabled_person_is_a_plain_validation_error_and_duplicates_are_refused(): void
    {
        $root = $this->platformAdmin();
        $disabled = $this->createUser(['is_disabled' => true]);
        $person = $this->createUser();

        foreach (['nobody@example.test', 'auditor', $disabled->email] as $identifier) {
            try {
                $this->governance()->grant($root, $identifier, 'platform_auditor');
                $this->fail("{$identifier} must not resolve to an eligible person");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('user', $e->errors());
            }
        }
        $this->assertSame([], $this->denials($root), 'Input validation is not an escalation signal.');

        $this->governance()->grant($root, $person->email, 'platform_auditor');
        try {
            $this->governance()->grant($root, $person->email, 'platform_auditor');
            $this->fail('A second active grant must be refused.');
        } catch (ValidationException) {
        }
        $this->assertEquals([['outcome_code' => 'already_granted', 'role_key' => 'platform_auditor']], $this->denials($root));
    }

    #[Test]
    public function the_roles_page_offers_only_the_auditor_role_and_lists_runtime_grants(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser(['email' => 'listed.auditor@example.test']);
        $this->governance()->grant($root, $person->email, 'platform_auditor');

        $response = $this->actingAs($root)->get('/app/platform/roles');
        $response->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Platform/Roles')
            ->where('role', ['key' => 'platform_auditor', 'name' => 'Platform Auditor'])
            ->has('grants', 1)
            ->where('grants.0.userEmail', 'listed.auditor@example.test')
        );
        $this->assertStringNotContainsString('platform_super_admin', $response->getContent());

        // The page's POST grants the auditor role whatever a client sends.
        $other = $this->createUser();
        $this->post('/app/platform/roles/grants', ['user' => $other->email, 'role' => 'platform_super_admin'])->assertRedirect();
        $this->assertSame(['platform.audit.view'], app(CapabilityResolver::class)->platformCapabilities($other));

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('platform.canViewAuditLog', true)
            ->where('platform.canGovernRoles', true)
        );
    }

    #[Test]
    public function the_platform_auditor_holds_exactly_one_capability_and_no_other_power(): void
    {
        $root = $this->platformAdmin();
        $auditor = $this->createUser();
        $this->governance()->grant($root, $auditor->email, 'platform_auditor');
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $resolver = app(CapabilityResolver::class);

        $this->assertSame(['platform.audit.view'], $resolver->platformCapabilities($auditor));
        $this->assertSame([], $resolver->schoolCapabilities($auditor, $school));
        $this->assertSame([], $resolver->groupCapabilities($auditor, $group));
        $this->assertSame(['platform.audit.view'], Role::query()->where('key', 'platform_auditor')->firstOrFail()->capabilities()->pluck('key')->all());

        $this->enrollActiveMfaFactor($auditor);
        $this->actingAs($auditor)->get('/app')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('activeSchool', null)
            ->where('platform.canViewAuditLog', true)
            ->where('platform.canGovernRoles', false)
            ->where('platformElevation.canStart', false)
            ->where('groups.canViewOwn', false)
            ->where('groups.canGovern', false)
        );
        $this->get('/app/platform/elevation')->assertForbidden();
        $this->post('/app/platform/elevation/confirm', ['target' => $school->id, 'reason_code' => 'operational_support'])->assertForbidden();
        $this->get('/app/platform/groups')->assertForbidden();
        $this->get("/app/groups/{$group->id}")->assertNotFound();
        $this->get('/app/platform/roles')->assertForbidden();
        $this->get('/app/students')->assertRedirect('/app');
        $this->post("/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');
    }
}
