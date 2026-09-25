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
 * Laravel's inbound half of the AI tool boundary (ADR 0013, ADR 0014,
 * ADR 0023). Phase 0B ships exactly one trivial, read-only tool
 * ("school-echo") to prove the full chain end to end -- it is NOT a
 * template for a customer-facing AI agent, and no business module is
 * exposed here. Every future AI tool contract endpoint must follow
 * this same shape: verify context token -> check its capability claim
 * -> set TenantContext -> call a normal, already-authorized domain
 * path -> audit -> clear context.
 */
class AiToolController extends Controller
{
    public function schoolEcho(Request $request, AiContextTokenService $tokens, TenantContext $context, AuditRecorder $audit): JsonResponse
    {
        $claims = $tokens->verify($request->string('context_token')->toString());

        if ($claims === null) {
            return response()->json(['error' => ['message' => 'Invalid or expired AI context token.']], 401);
        }

        if (! $claims->hasCapability('school.settings.view')) {
            return response()->json(['error' => ['message' => 'Context token lacks required capability.']], 403);
        }

        $school = School::find($claims->schoolId);
        $actor = User::find($claims->actorId);

        if ($school === null || $actor === null) {
            return response()->json(['error' => ['message' => 'Context token references an unknown School or actor.']], 422);
        }

        // Phase 0N.9 (ADR 0047 section 8): the School must be active NOW --
        // a token minted while it was active proves nothing after a
        // suspension. Refused before any School context or source access.
        if (! $school->isActive()) {
            return response()->json(['error' => ['message' => 'That School is not available.', 'code' => 'school_unavailable']], 403);
        }

        try {
            $context->set($school);
            $context->setActor($actor);
            $context->setRequestId($claims->requestId);

            $audit->school($school, 'ai.tool_invoked', actor: $actor, metadata: ['tool' => 'school-echo']);

            return response()->json(['result' => [
                'schoolId' => $school->id,
                'schoolName' => $school->name,
            ]]);
        } finally {
            $context->clearAll();
        }
    }
}
