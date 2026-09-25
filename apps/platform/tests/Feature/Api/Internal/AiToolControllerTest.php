<?php

namespace Tests\Feature\Api\Internal;

use App\Models\SchoolAuditEvent;
use App\Support\Ai\AiContextTokenService;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 28: inbound half of the AI tool boundary. Laravel's own
 * authorization (verifying the signed context token, ADR 0023) is what
 * actually enforces "missing required context fails" and "unrelated
 * capability fails" -- not services/ai, which never holds the signing
 * key and is a lower-trust caller by design (ADR 0008).
 */
class AiToolControllerTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const SERVICE_TOKEN = 'dev-local-only-token';

    #[Test]
    public function it_rejects_calls_without_a_valid_service_token(): void
    {
        $response = $this->postJson('/api/internal/ai/tools/school-echo', [
            'context_token' => 'irrelevant',
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_missing_context_token(): void
    {
        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', []);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_forged_context_token(): void
    {
        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => 'not.avalidtoken']);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_valid_token_whose_capability_does_not_match_the_tool(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $tokenService = app(AiContextTokenService::class);

        // Signed correctly, but for an UNRELATED capability -- the
        // school-echo tool requires school.settings.view specifically.
        $token = $tokenService->issue($school, $user, ['school.members.manage']);

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token]);

        $response->assertForbidden();
    }

    #[Test]
    public function it_rejects_an_expired_context_token(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $tokenService = app(AiContextTokenService::class);

        $this->travelTo(now()->subMinutes(5));
        $token = $tokenService->issue($school, $user, ['school.settings.view']);
        $this->travelBack();

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function a_valid_token_with_the_right_capability_succeeds_sets_context_and_audits(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $tokenService = app(AiContextTokenService::class);
        $token = $tokenService->issue($school, $user, ['school.settings.view'], 'req-ai-1');

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token]);

        $response->assertOk();
        $response->assertJsonPath('result.schoolId', $school->id);
        $response->assertJsonPath('result.schoolName', $school->name);

        $auditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'ai.tool_invoked')->count(),
        );
        $this->assertSame(1, $auditCount);

        // Context must not leak past this request.
        $this->assertFalse(app(TenantContext::class)->hasSchool());
    }

    #[Test]
    public function a_token_for_school_a_cannot_be_used_to_read_school_b(): void
    {
        // The token itself carries the School id it was signed for --
        // there is no separate "school_id" parameter an attacker could
        // swap independently of the signed token.
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $tokenService = app(AiContextTokenService::class);
        $token = $tokenService->issue($schoolA, $userA, ['school.settings.view']);

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token]);

        $response->assertOk();
        // Necessarily School A -- the token cannot name School B
        // without Laravel's own signing key, which services/ai never
        // has (ADR 0023).
        $response->assertJsonPath('result.schoolId', $schoolA->id);
        $this->assertNotSame($schoolB->id, $response->json('result.schoolId'));
    }

    /**
     * Phase 0O.1: with no signing key configured, a token HMAC-signed with
     * an empty key (which anyone can compute) is never accepted -- the
     * tool fails closed and nothing is audited.
     */
    #[Test]
    public function a_missing_signing_key_fails_closed_instead_of_accepting_an_empty_key_token(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'school_id' => $school->id, 'actor_id' => $user->id,
            'capabilities' => ['school.settings.view'], 'request_id' => null, 'exp' => now()->addMinute()->timestamp,
        ])), '+/', '-_'), '=');
        $forged = $payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, '', true)), '+/', '-_'), '=');

        config(['services.ai_gateway.context_signing_key' => null]);
        $this->app->forgetInstance(AiContextTokenService::class);

        $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $forged])
            ->assertStatus(500);

        $this->assertSame(0, app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'ai.tool_invoked')->count(),
        ));
    }
}
