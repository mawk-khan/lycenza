<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Durable-audit write-back for the AI Gateway (Phase 0C section 58/69,
 * ADR 0023). Resolves the Phase 0B AI-side audit debt: services/ai's
 * in-process AuditLedger (app/audit/ledger.py) is not durable and is
 * not a shadow database -- every AI Gateway action is instead recorded
 * here, in Laravel's own authoritative SchoolAuditEvent store, exactly
 * like every other audit event in this system.
 *
 * Reuses the SAME signed AiContextTokenService envelope the AI tool
 * boundary already uses (ADR 0023) to identify which School/actor the
 * action concerns -- this endpoint does not mint or need a separate
 * credential type. The `service-auth` middleware
 * (App\Http\Middleware\AuthenticateServiceAssertion, ADR 0053) separately
 * proves the CALLER (the `ai-gateway` service identity, scope
 * `ai.audit.write`) is entitled to write audit entries at all; the
 * context token proves WHICH School/actor
 * the entry is about. Neither check alone is sufficient.
 */
class AiAuditController extends Controller
{
    /** Agent, tool, action, provider, model and outcome are identifiers, never text. */
    private const IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/';

    public function store(Request $request, AiContextTokenService $tokens, TenantContext $context, AuditRecorder $audit): JsonResponse
    {
        // Identifiers and numbers only (gaps G2/G3): free text can never be
        // smuggled into audit metadata -- no prompt, output, argument or
        // provider body reaches this ledger.
        $validated = $request->validate([
            'context_token' => ['required', 'string', 'max:4096'],
            'agent' => ['required', 'string', 'regex:'.self::IDENTIFIER],
            'tool' => ['sometimes', 'nullable', 'string', 'regex:'.self::IDENTIFIER],
            'action' => ['required', 'string', 'regex:'.self::IDENTIFIER],
            'provider' => ['sometimes', 'nullable', 'string', 'regex:'.self::IDENTIFIER],
            'model' => ['sometimes', 'nullable', 'string', 'regex:'.self::IDENTIFIER],
            'outcome' => ['sometimes', 'nullable', 'string', 'regex:'.self::IDENTIFIER],
            'latency_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600000'],
            'input_tokens' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'output_tokens' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $claims = $tokens->verify($validated['context_token']);

        if ($claims === null) {
            return response()->json(['error' => ['message' => 'Invalid or expired AI context token.']], 401);
        }

        // The caller is expected to name the School it believes it is
        // acting for; the signed token is the only authoritative source
        // of truth. A mismatch means the caller and the token disagree
        // about which School this action concerns, so it is rejected
        // rather than silently trusting either side.
        $requestedSchoolId = $request->string('school_id')->toString();
        if ($requestedSchoolId !== '' && $requestedSchoolId !== $claims->schoolId) {
            return response()->json(['error' => ['message' => 'school_id does not match the AI context token.']], 422);
        }

        $school = School::find($claims->schoolId);
        $actor = User::find($claims->actorId);

        if ($school === null || $actor === null) {
            return response()->json(['error' => ['message' => 'Context token references an unknown School or actor.']], 422);
        }

        try {
            $context->set($school);
            $context->setActor($actor);
            $context->setRequestId($claims->requestId);

            $event = $audit->school($school, 'ai.gateway_action_recorded', actor: $actor, metadata: array_merge([
                'agent' => $validated['agent'],
                'tool' => ($validated['tool'] ?? null) ?: null,
                'action' => $validated['action'],
            ], array_filter([
                'provider' => $validated['provider'] ?? null,
                'model' => $validated['model'] ?? null,
                'outcome' => $validated['outcome'] ?? null,
                'latencyMs' => $validated['latency_ms'] ?? null,
                'inputTokens' => $validated['input_tokens'] ?? null,
                'outputTokens' => $validated['output_tokens'] ?? null,
            ], fn ($value) => $value !== null)));

            return response()->json(['audit' => [
                'id' => $event->id,
                'recordedAt' => $event->occurred_at->toIso8601String(),
            ]]);
        } finally {
            $context->clearAll();
        }
    }
}
