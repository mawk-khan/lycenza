<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0A primitive: proves the versioned JSON API envelope
 * (data/meta, request id, error format) described in
 * docs/architecture/API.md. Unauthenticated by design — it carries no
 * tenant or business data. Every future authenticated endpoint must sit
 * behind Sanctum/token auth + policy-based authorization, neither of
 * which is implemented yet.
 */
class SystemStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'phase' => '0A - Architectural Foundation',
                'environment' => config('app.env'),
            ],
            'meta' => [
                'apiVersion' => 'v1',
                'requestId' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
