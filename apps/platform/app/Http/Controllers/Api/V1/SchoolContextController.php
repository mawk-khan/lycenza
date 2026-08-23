<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0B primitive proving the Flutter/mobile-facing chain (section
 * 40): Sanctum identity -> real membership check -> School context ->
 * effective capabilities -> response. Not the Flutter business app --
 * one endpoint proving every layer works together over the versioned
 * API surface a mobile client actually uses.
 */
class SchoolContextController extends Controller
{
    public function show(Request $request, School $school, TenantContext $context, CapabilityResolver $capabilities): JsonResponse
    {
        $user = $request->user();

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! $school->isActive()) {
            return response()->json([
                'error' => [
                    'message' => 'Not found.',
                    'status' => 404,
                    'requestId' => $request->attributes->get('request_id'),
                ],
            ], 404);
        }

        $context->set($school);
        $context->setActor($user);

        return response()->json([
            'data' => [
                'school' => ['id' => $school->id, 'name' => $school->name],
                'capabilities' => $capabilities->schoolCapabilities($user, $school),
            ],
            'meta' => [
                'apiVersion' => 'v1',
                'requestId' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
