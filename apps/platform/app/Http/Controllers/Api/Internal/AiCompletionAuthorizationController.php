<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Completion authorization for the AI Gateway (gap G1,
 * docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md). The gateway holds
 * no signing key (ADR 0023), so before it runs ANY model completion it
 * asks Laravel to verify the signed context token here -- the same
 * envelope the tool contracts verify -- and gets back the only
 * authoritative School and actor for the call.
 *
 * In order: the `ai-service:ai.tools.invoke` middleware proves the caller
 * is the gateway's service identity; the token's signature and expiry are
 * verified; the capability the agent needs must be in the token's claim;
 * an optional caller-named School must match the token; and the actor must
 * STILL hold that capability in that School now (CapabilityResolver --
 * stricter than trusting a claim minted up to 60 seconds ago). Nothing is
 * executed or audited here; the gateway audits the completion afterwards
 * through the existing write-back (AiAuditController).
 */
class AiCompletionAuthorizationController extends Controller
{
    public function authorizeCompletion(Request $request, AiContextTokenService $tokens, CapabilityResolver $capabilities): JsonResponse
    {
        $validated = $request->validate([
            'context_token' => ['required', 'string', 'max:4096'],
            'capability' => ['required', 'string', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)+$/'],
            'school_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $claims = $tokens->verify($validated['context_token']);

        if ($claims === null) {
            return $this->refuse('context_invalid', 401);
        }

        if (! $claims->hasCapability($validated['capability'])) {
            return $this->refuse('capability_not_in_context', 403);
        }

        if (($validated['school_id'] ?? null) !== null && $validated['school_id'] !== $claims->schoolId) {
            return $this->refuse('school_mismatch', 422);
        }

        $school = School::query()->find($claims->schoolId);
        $actor = User::query()->find($claims->actorId);

        if ($school === null || $actor === null) {
            return $this->refuse('unknown_school_or_actor', 422);
        }

        $capabilities->forgetCache($actor, $school);
        if (! $capabilities->canInSchool($actor, $validated['capability'], $school)) {
            return $this->refuse('capability_revoked', 403);
        }

        return response()->json(['authorization' => [
            'schoolId' => $school->id,
            'actorId' => $actor->id,
            'requestId' => $claims->requestId,
        ]]);
    }

    private function refuse(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
