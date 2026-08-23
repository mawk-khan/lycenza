<?php

namespace Tests\Feature\Api\Internal;

use App\Models\SchoolAuditEvent;
use App\Support\Ai\AiContextTokenService;
use App\Support\ServiceIdentities\ServiceIdentityIssuer;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Required end-to-end Proof C (Phase 0C section 69): the AI Gateway's
 * durable-audit write-back into Laravel's authoritative SchoolAuditEvent
 * store, plus the four required denial scenarios: invalid service
 * identity, invalid context signature, School mismatch, and unrelated
 * service capability.
 */
class AiAuditControllerTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const SERVICE_TOKEN = 'dev-local-only-token';

    #[Test]
    public function it_rejects_an_invalid_service_identity(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, ['school.settings.view']);

        $response = $this->withToken('not-a-real-service-credential')
            ->postJson('/api/internal/ai/audit', [
                'context_token' => $token,
                'agent' => 'phase0b-proof-agent',
                'action' => 'tool.invoke',
            ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_an_invalid_context_signature(): void
    {
        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/audit', [
                'context_token' => 'tampered.notavalidsignature',
                'agent' => 'phase0b-proof-agent',
                'action' => 'tool.invoke',
            ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_school_id_that_does_not_match_the_context_token(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $token = app(AiContextTokenService::class)->issue($schoolA, $userA, ['school.settings.view']);

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/audit', [
                'context_token' => $token,
                'school_id' => $schoolB->id,
                'agent' => 'phase0b-proof-agent',
                'action' => 'tool.invoke',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_a_service_identity_that_lacks_the_audit_write_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, ['school.settings.view']);

        // A real, currently-valid service identity -- but scoped ONLY
        // to tool invocation, never granted ai.audit.write. Proves
        // "a valid credential does not imply access to every internal
        // capability" (section 27), exercising the SAME
        // ServiceIdentityAuthenticator that verifies the AI Gateway's
        // own identity, not a second/parallel authorization mechanism.
        [, $credential] = app(ServiceIdentityIssuer::class)->issue(
            'unrelated-caller', 'Unrelated Test Caller', ['ai.tools.invoke'], null,
        );

        $response = $this->withToken($credential)
            ->postJson('/api/internal/ai/audit', [
                'context_token' => $token,
                'agent' => 'phase0b-proof-agent',
                'action' => 'tool.invoke',
            ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function a_valid_call_writes_a_durable_audit_event_and_returns_its_id(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, ['school.settings.view'], 'req-audit-1');

        $response = $this->withToken(self::SERVICE_TOKEN)
            ->postJson('/api/internal/ai/audit', [
                'context_token' => $token,
                'school_id' => $school->id,
                'agent' => 'phase0b-proof-agent',
                'tool' => 'school.echo',
                'action' => 'tool.invoke',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('audit.id'));
        $this->assertNotNull($response->json('audit.recordedAt'));

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'ai.gateway_action_recorded')->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($school->id, $event->school_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('school.echo', $event->metadata['tool']);
        $this->assertSame($response->json('audit.id'), $event->id);

        // Context must not leak past this request.
        $this->assertFalse(app(TenantContext::class)->hasSchool());
    }
}
