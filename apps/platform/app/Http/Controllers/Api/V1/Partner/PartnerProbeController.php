<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.3 (ADR 0049 section 6.4): the partner substrate's probe --
 * registered only in `local`/`testing` (PartnerScopeRegistry), never in
 * production. It answers who the partner is and which School the request
 * runs under, and proves the RLS boundary with a raw query: only the bound
 * School's campuses are visible. No business data beyond counts.
 */
class PartnerProbeController extends Controller
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user();

        return response()->json([
            'data' => [
                'apiClientId' => $client->id,
                'schoolId' => $context->requireSchool()->id,
                'rlsSchoolIds' => DB::table('campuses')->distinct()->orderBy('school_id')->pluck('school_id')->all(),
                'sessionSchool' => DB::selectOne("select current_setting('app.current_school_id', true) as v")->v,
            ],
        ]);
    }
}
