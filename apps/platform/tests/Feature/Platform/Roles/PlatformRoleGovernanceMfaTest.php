<?php

namespace Tests\Feature\Platform\Roles;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.1A (ADR 0076 §24, V-2): platform-role grant and revoke re-verify a
 * fresh MFA code at the authoritative write path
 * (PlatformRoleGovernanceService), not only in the page. A missing, wrong,
 * replayed or already-used code -- or mere sign-in assurance -- is refused,
 * and a refusal changes no assignment. The runtime-assignable allowlist and
 * the database governance trigger are unchanged.
 */
class PlatformRoleGovernanceMfaTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function governance(): PlatformRoleGovernanceService
    {
        return app(PlatformRoleGovernanceService::class);
    }

    /** @param  array<string, mixed>  $body */
    private function request(array $body = []): Request
    {
        return Request::create('/app/platform/roles/grants', 'POST', $body);
    }

    private function freshCode(User $user): string
    {
        UserMfaFactor::query()->where('user_id', $user->id)->update(['last_used_totp_step' => null]);

        return $this->totpFor($user);
    }

    /** @return list<array<string, mixed>> */
    private function denials(User $actor): array
    {
        return PlatformAuditEvent::query()->where('actor_user_id', $actor->id)->where('event_type', 'platform.role_grant.denied')
            ->orderBy('occurred_at')->orderBy('id')->pluck('metadata')->all();
    }

    private function activeGrants(User $target): int
    {
        return PlatformRoleAssignment::query()->where('user_id', $target->id)->active()->count();
    }

    private function grantedAuditor(User $root, User $person): PlatformRoleAssignment
    {
        return $this->governance()->grant($this->request(['mfa_code' => $this->freshCode($root)]), $root, $person->email, 'platform_auditor');
    }

    private function assertRefused(callable $action, string $field = 'mfa_code'): void
    {
        try {
            $action();
            $this->fail('The governance write must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    #[Test]
    public function a_grant_with_a_fresh_code_succeeds(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();

        $grant = $this->grantedAuditor($root, $person);

        $this->assertSame($root->id, $grant->granted_by_user_id);
        $this->assertSame(1, $this->activeGrants($person));
        $this->assertEqualsCanonicalizing(['platform.audit.view', 'platform.schools.view', 'platform.school_groups.view'], app(CapabilityResolver::class)->platformCapabilities($person));
        $this->assertSame([], $this->denials($root));
    }

    #[Test]
    public function a_grant_without_a_code_or_with_only_sign_in_assurance_is_refused_and_changes_nothing(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();

        $this->assertRefused(fn () => $this->governance()->grant($this->request(), $root, $person->email, 'platform_auditor'));
        $this->assertRefused(fn () => $this->governance()->grant($this->request(['mfa_code' => '   ']), $root, $person->email, 'platform_auditor'));

        // A session verified a moment ago is still not a fresh code.
        $this->actingAs($root)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->post('/app/platform/roles/grants', ['user' => $person->email, 'role' => 'platform_auditor'])
            ->assertSessionHasErrors('mfa_code');

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $person->id)->count());
        $this->assertSame([], $this->denials($root), 'A missing code is plain validation, not an escalation signal.');
    }

    #[Test]
    public function a_wrong_replayed_or_used_code_is_refused_audited_and_changes_nothing(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();

        $this->assertRefused(fn () => $this->governance()->grant($this->request(['mfa_code' => '000000']), $root, $person->email, 'platform_auditor'));

        // A TOTP code that has already been used (its step is consumed) is stale.
        $code = $this->freshCode($root);
        $first = $this->createUser();
        $this->governance()->grant($this->request(['mfa_code' => $code]), $root, $first->email, 'platform_auditor');
        $this->assertRefused(fn () => $this->governance()->grant($this->request(['mfa_code' => $code]), $root, $person->email, 'platform_auditor'));

        // A recovery code is single-use.
        [$recovery] = $this->issueRecoveryCodes($root, 1);
        $second = $this->createUser();
        $this->governance()->grant($this->request(['mfa_code' => $recovery]), $root, $second->email, 'platform_auditor');
        $this->assertRefused(fn () => $this->governance()->grant($this->request(['mfa_code' => $recovery]), $root, $person->email, 'platform_auditor'));

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $person->id)->count());
        $this->assertEquals(
            array_fill(0, 3, ['outcome_code' => 'mfa_verification_failed', 'role_key' => 'platform_auditor']),
            $this->denials($root),
        );
    }

    #[Test]
    public function an_actor_without_an_enrolled_factor_cannot_govern_platform_roles(): void
    {
        $root = $this->platformAdmin(withMfa: false);
        $person = $this->createUser();

        $this->assertRefused(fn () => $this->governance()->grant($this->request(['mfa_code' => '123456']), $root, $person->email, 'platform_auditor'));

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $person->id)->count());
        $this->assertEquals([['outcome_code' => 'mfa_not_enrolled', 'role_key' => 'platform_auditor']], $this->denials($root));
    }

    #[Test]
    public function a_revoke_needs_a_fresh_code_and_a_refusal_leaves_the_grant_active(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();
        $grant = $this->grantedAuditor($root, $person);
        $usedCode = $this->freshCode($root);
        $this->governance()->grant($this->request(['mfa_code' => $usedCode]), $root, $this->createUser()->email, 'platform_auditor');

        $this->assertRefused(fn () => $this->governance()->revoke($this->request(), $root, $grant));
        $this->assertRefused(fn () => $this->governance()->revoke($this->request(['mfa_code' => '000000']), $root, $grant));
        $this->assertRefused(fn () => $this->governance()->revoke($this->request(['mfa_code' => $usedCode]), $root, $grant));
        $this->actingAs($root)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->post("/app/platform/roles/grants/{$grant->id}/revoke")
            ->assertSessionHasErrors('mfa_code');

        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertEqualsCanonicalizing(['platform.audit.view', 'platform.schools.view', 'platform.school_groups.view'], app(CapabilityResolver::class)->platformCapabilities($person));
        $this->assertSame(0, PlatformAuditEvent::query()->where('event_type', 'platform.role_grant.revoked')->count());

        $this->governance()->revoke($this->request(['mfa_code' => $this->freshCode($root)]), $root, $grant);

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame([], app(CapabilityResolver::class)->platformCapabilities($person));
        $this->assertSame(1, PlatformAuditEvent::query()->where('event_type', 'platform.role_grant.revoked')->count());
    }

    #[Test]
    public function the_roles_page_grants_and_revokes_with_a_fresh_code(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();

        $this->actingAs($root)->post('/app/platform/roles/grants', ['user' => $person->email, 'role' => 'platform_auditor', 'mfa_code' => $this->freshCode($root)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $grant = PlatformRoleAssignment::query()->where('user_id', $person->id)->active()->firstOrFail();

        $this->post("/app/platform/roles/grants/{$grant->id}/revoke", ['mfa_code' => $this->freshCode($root)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    #[Test]
    public function a_fresh_code_never_unlocks_the_root_role_or_another_actors_authority(): void
    {
        $root = $this->platformAdmin();
        $person = $this->createUser();

        $this->assertRefused(
            fn () => $this->governance()->grant($this->request(['mfa_code' => $this->freshCode($root)]), $root, $person->email, 'platform_super_admin'),
            'role',
        );

        $auditor = $this->createUser();
        $this->grantedAuditor($root, $auditor);
        $this->enrollActiveMfaFactor($auditor);
        $this->actingAs($auditor)->post('/app/platform/roles/grants', ['user' => $person->email, 'role' => 'platform_auditor', 'mfa_code' => '123456'])->assertForbidden();

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $person->id)->count());
        // The read-only Platform Auditor still reviews the audit log with current assurance -- no code.
        $this->withSession(['mfa_verified_at' => now()->toIso8601String()])->get('/app/platform/audit-log')->assertOk();
    }
}
