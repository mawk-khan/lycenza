<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8A.14 -- the sole HTTP transport for the 8A.9 Employee Profile
 * Workspace. Read-only adapter over
 * `EmployeeProfileWorkspaceService::build()`, whose own `toArray()`
 * (via `EmployeeProfileWorkspace`) is already the complete, exhaustive,
 * section-based transport contract -- this controller performs NO
 * additional mapping, filtering, or capability logic of its own.
 *
 * `$employee` is a raw route-parameter STRING, never an implicit
 * Eloquent route-model binding -- the service resolves it under
 * `$school`'s tenant scope itself and returns `null` for both a
 * genuinely nonexistent Employee id and one belonging to a different
 * School (identical, non-enumerating outcome), which this controller
 * turns into an ordinary 404. Mirrors
 * `App\Http\Controllers\Api\V1\WebhookDeliveryController`'s documented
 * "resolve inside the controller/service action, never via implicit
 * binding" pattern.
 */
class EmployeeProfileController extends Controller
{
    public function show(Request $request, School $school, string $employee): JsonResponse
    {
        $workspace = app(EmployeeProfileWorkspaceService::class)->build($school, $employee, $request->user());

        abort_if($workspace === null, 404);

        return response()->json(['data' => $workspace->toArray()]);
    }
}
