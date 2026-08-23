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
 * credential type. The `ai-service:ai.audit.write` middleware
 * (App\Http\Middleware\VerifyAiGatewayServiceToken) separately proves
 * the CALLER (the AI Gateway service identity) is entitled to write
 * audit entries at all; the context token proves WHICH School/actor
 * the entry is about. Neither check alone is sufficient.
 */
class AiAuditController extends Controller
{
    public function store(Request $request, AiContextTokenService $tokens, TenantContext $context, AuditRecorder $audit): JsonResponse
    {
        $claims = $tokens->verify($request->string('context_token')->toString());

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

            $event = $audit->school($school, 'ai.gateway_action_recorded', actor: $actor, metadata: [
                'agent' => $request->string('agent')->toString(),
                'tool' => $request->string('tool')->toString() ?: null,
                'action' => $request->string('action')->toString(),
            ]);

            return response()->json(['audit' => [
                'id' => $event->id,
                'recordedAt' => $event->occurred_at->toIso8601String(),
            ]]);
        } finally {
            $context->clearAll();
        }
    }
}
