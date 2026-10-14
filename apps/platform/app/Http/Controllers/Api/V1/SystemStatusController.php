<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unauthenticated API status probe: proves the versioned JSON API
 * envelope (data/meta, request id, error format) described in
 * docs/architecture/API.md. Unauthenticated by design -- it carries no
 * tenant or business data.
 *
 * PPR.1 (ADR 0074 section 26.6, finding F-8): it no longer discloses the
 * deployment environment (`environment`) or the development-era `phase`
 * label to anonymous callers. Health is `/api/health/live` and `/ready`;
 * an operator's environment view is `platform:operations-status` /
 * `GET /api/internal/operations/status` (platform-authorized).
 */
class SystemStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
            ],
            'meta' => [
                'apiVersion' => 'v1',
                'requestId' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
