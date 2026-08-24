<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        // Phase 8A.15: a malformed (non-UUID) id would otherwise reach
        // the service's `Employee::query()->where('school_id', ...)->find($employeeId)`
        // and crash PostgreSQL with `invalid input syntax for type
        // uuid` (a raw 500, confirmed empirically during this
        // checkpoint's own abuse-input testing) -- rejected here as
        // the SAME tenant-safe 404 a genuinely nonexistent/cross-School
        // Employee already produces, never a distinguishing error.
        abort_if(! Str::isUuid($employee), 404);

        $workspace = app(EmployeeProfileWorkspaceService::class)->build($school, $employee, $request->user());

        abort_if($workspace === null, 404);

        return response()->json(['data' => $workspace->toArray()]);
    }
}
