<?php

namespace App\Support\Ai;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Observability\TraceContext;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

/**
 * Laravel's outbound half of the AI tool boundary (ADR 0013, ADR 0014,
 * ADR 0023). The ONLY way application code invokes an AI Gateway tool.
 *
 * Order matters: capability is verified FIRST, against the real
 * membership/role data for (actor, school), and only THEN is a context
 * token minted -- scoped to exactly the capability just verified. This
 * is what proves "an actor from School A cannot create an authorized
 * tool context for School B": there is no code path that mints a token
 * without this check passing first, and services/ai never receives the
 * signing key needed to mint one itself.
 */
class AiGatewayClient
{
    public function __construct(
        private readonly AiContextTokenService $tokens,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function invokeTool(User $actor, School $school, string $capability, string $agent, string $tool, array $payload = []): array
    {
        $this->assertMintable($actor, $school, $capability);

        $contextToken = $this->tokens->issue($school, $actor, [$capability], $this->context->requestId());

        // X-Service-Token, not Authorization: Bearer -- must match
        // services/ai's require_service_token dependency
        // (app/core/security.py), which is the Phase 0A convention for
        // Laravel -> AI Gateway calls specifically. This is the
        // opposite direction from AiToolController's inbound check
        // (App\Http\Middleware\VerifyAiGatewayServiceToken), which
        // correctly uses Authorization: Bearer for AI Gateway ->
        // Laravel calls -- the two directions are deliberately
        // independent conventions, not the same header reused.
        //
        // Section 43: propagate the CURRENT trace (this request's),
        // with a fresh child span-id for this specific outbound hop --
        // the AI Gateway's own inbound handling mints a further child
        // span from this when it, in turn, calls back to Laravel's
        // audit write-back endpoint (services/ai's app/core/trace.py).
        $currentTraceId = $this->context->traceId();
        $childSpan = $currentTraceId !== null
            ? TraceContext::forTraceId($currentTraceId)
            : TraceContext::start();

        $response = Http::withHeaders([
            'X-Service-Token' => (string) config('services.ai_gateway.service_token'),
            'traceparent' => $childSpan->toHeader(),
        ])
            ->baseUrl((string) config('services.ai_gateway.base_url'))
            ->timeout(5)
            ->post('/v1/tools/invoke', [
                'school_id' => $school->id,
                'agent' => $agent,
                'tool' => $tool,
                'context_token' => $contextToken,
                // (object) cast: an empty PHP array json_encodes as
                // `[]`, but the AI Gateway's payload field requires a
                // JSON object (`{}`) even when empty.
                'payload' => (object) $payload,
            ]);

        $response->throw();

        return $response->json('result') ?? [];
    }

    /**
     * A model completion through the AI Gateway, under exactly the same rule
     * as invokeTool(): the actor must hold `$capability` in `$school` BEFORE
     * a context token is minted, and the token carries only that
     * capability. The gateway then has Laravel verify the token
     * (AiCompletionAuthorizationController) before any provider runs, and
     * audits the call durably. No production code calls this yet: Phase 0M
     * is BLOCKED (docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md), and
     * the gateway only has the offline NullProvider.
     *
     * @return array{text: string, provider: string, model: string}
     */
    public function complete(User $actor, School $school, string $capability, string $agent, string $prompt): array
    {
        $this->assertMintable($actor, $school, $capability);

        $contextToken = $this->tokens->issue($school, $actor, [$capability], $this->context->requestId());

        $currentTraceId = $this->context->traceId();
        $childSpan = $currentTraceId !== null
            ? TraceContext::forTraceId($currentTraceId)
            : TraceContext::start();

        $response = Http::withHeaders([
            'X-Service-Token' => (string) config('services.ai_gateway.service_token'),
            'traceparent' => $childSpan->toHeader(),
        ])
            ->baseUrl((string) config('services.ai_gateway.base_url'))
            ->timeout(10)
            ->post('/v1/complete', [
                'agent' => $agent,
                'context_token' => $contextToken,
                'prompt' => $prompt,
            ]);

        $response->throw();

        return [
            'text' => (string) $response->json('text'),
            'provider' => (string) $response->json('provider'),
            'model' => (string) $response->json('model'),
        ];
    }

    /**
     * Checked BEFORE any context token is minted: the actor holds the
     * capability in that School, and (Phase 0N.9, ADR 0047 section 8) the
     * School is `active` right now -- read fresh from the database, never
     * from the passed model -- so a provisioning, suspended or archived
     * School gets no AI context at all. Elevation never reaches here
     * (tokens resolve no elevated context, CLAUDE.md rule 83).
     */
    private function assertMintable(User $actor, School $school, string $capability): void
    {
        if (! $this->operational->isOperational($school->id)) {
            throw new AiGatewayAuthorizationException(
                "School {$school->id} is not active; refusing to mint an AI context token."
            );
        }

        if (! $this->capabilities->canInSchool($actor, $capability, $school)) {
            throw new AiGatewayAuthorizationException(
                "User {$actor->id} does not hold capability '{$capability}' in School {$school->id}; refusing to mint an AI context token."
            );
        }
    }
}
