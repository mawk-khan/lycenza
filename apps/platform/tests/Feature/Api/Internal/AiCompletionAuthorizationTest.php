<?php

namespace Tests\Feature\Api\Internal;

use App\Models\MembershipRoleAssignment;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Support\Ai\AiContextTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\SignsServiceAssertions;
use Tests\TestCase;

/**
 * Gap G1 (docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md): the AI
 * Gateway has Laravel verify the signed context token before ANY model
 * completion. Laravel alone holds the key (ADR 0023) and returns the only
 * authoritative School/actor; every refusal is a stable code, and nothing
 * is executed or audited here.
 */
class AiCompletionAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures, SignsServiceAssertions;

    private const URL = '/api/internal/ai/completions/authorize';

    private array $gatewayKey;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR 0053: a runtime-generated `ai-gateway` key; Laravel trusts only
        // its public half, and every request below is signed with it unless
        // it names another Authorization value.
        $this->gatewayKey = $this->serviceKey('test-ai-gateway-1');
        $this->useServiceKeys([$this->publicJwk($this->gatewayKey)]);
        $this->asService($this->gatewaySigner($this->gatewayKey));
    }

    private const CAPABILITY = 'school.settings.view';

    private function authorizeWith(string $token, string $capability = self::CAPABILITY, array $extra = []): TestResponse
    {
        return $this->postJson(self::URL, [
            'context_token' => $token,
            'capability' => $capability,
            ...$extra,
        ]);
    }

    #[Test]
    public function a_valid_context_returns_the_authoritative_school_and_actor_only(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, [self::CAPABILITY], 'req-complete-1');

        $response = $this->authorizeWith($token, extra: ['school_id' => $school->id])
            ->assertOk()
            ->assertExactJson(['authorization' => ['schoolId' => $school->id, 'actorId' => $user->id, 'requestId' => 'req-complete-1']]);

        $this->assertStringNotContainsString($token, (string) $response->getContent());
        $this->assertFalse(app(TenantContext::class)->hasSchool());
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->count()), 'authorization audits nothing');
    }

    #[Test]
    public function the_gateway_service_identity_is_required(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, [self::CAPABILITY]);

        $this->withToken('not-a-real-credential')->postJson(self::URL, ['context_token' => $token, 'capability' => self::CAPABILITY])->assertUnauthorized();

        // ADR 0053: an assertion meant for the Gateway (wrong audience) or from
        // the `platform` identity is refused here -- a valid context token
        // never substitutes for the calling service's own authentication.
        $key = $this->gatewayKey;
        $this->asService($this->gatewaySigner($key, audience: 'lycenza-ai-gateway'))
            ->postJson(self::URL, ['context_token' => $token, 'capability' => self::CAPABILITY])->assertUnauthorized();
        $this->asService($this->gatewaySigner($key, issuer: 'platform'))
            ->postJson(self::URL, ['context_token' => $token, 'capability' => self::CAPABILITY])->assertUnauthorized();
    }

    #[Test]
    public function missing_malformed_tampered_and_expired_tokens_are_refused(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, [self::CAPABILITY]);

        $this->postJson(self::URL, ['capability' => self::CAPABILITY])->assertUnprocessable();
        $this->authorizeWith('not-a-token')->assertUnauthorized()->assertExactJson(['error' => ['code' => 'context_invalid']]);
        $this->authorizeWith(substr($token, 0, -3).'AAA')->assertUnauthorized();
        [$payload] = explode('.', $token);
        $this->authorizeWith($payload.'.forged-signature')->assertUnauthorized();

        $this->travel(61)->seconds();
        $this->authorizeWith($token)->assertUnauthorized()->assertExactJson(['error' => ['code' => 'context_invalid']]);
    }

    #[Test]
    public function a_token_without_the_agents_capability_is_refused(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, ['school.members.view']);

        $this->authorizeWith($token)->assertForbidden()->assertExactJson(['error' => ['code' => 'capability_not_in_context']]);
        $this->authorizeWith($token, 'not a capability')->assertUnprocessable();
    }

    #[Test]
    public function a_request_naming_another_school_is_refused(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $token = app(AiContextTokenService::class)->issue($schoolA, $user, [self::CAPABILITY]);

        $this->authorizeWith($token, extra: ['school_id' => $schoolB->id])
            ->assertUnprocessable()
            ->assertExactJson(['error' => ['code' => 'school_mismatch']]);
    }

    #[Test]
    public function a_capability_revoked_or_an_actor_disabled_after_minting_is_refused(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, [self::CAPABILITY]);

        app(TenantContext::class)->withSchool($school, function () use ($user, $school): void {
            $membership = SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->firstOrFail();
            MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->active()->update(['revoked_at' => now(), 'revocation_reason' => MembershipRoleAssignment::REASON_REVOKED]);
        });

        $this->authorizeWith($token)->assertForbidden()->assertExactJson(['error' => ['code' => 'capability_revoked']]);

        [$other, $otherSchool] = $this->createSchoolAdmin('school_admin');
        $otherToken = app(AiContextTokenService::class)->issue($otherSchool, $other, [self::CAPABILITY]);
        $other->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();

        $this->authorizeWith($otherToken)->assertForbidden();
    }

    #[Test]
    public function platform_admin_has_no_implicit_school_completion_authority(): void
    {
        $school = $this->createSchool();
        $platformAdmin = $this->createPlatformRoot();
        // Even a token that claims the capability (as a compromised minter
        // might) is refused: the actor holds nothing in that School.
        $token = app(AiContextTokenService::class)->issue($school, $platformAdmin, [self::CAPABILITY]);

        $this->authorizeWith($token)->assertForbidden()->assertExactJson(['error' => ['code' => 'capability_revoked']]);
    }
}
