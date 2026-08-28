<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeImportService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8A closure correction (item 7 -- Employee Import activation) --
 * the sole HTTP transport for `EmployeeImportService::import()`, which
 * until this correction had a fully-built, tested Application-layer
 * orchestrator with no way to reach it over HTTP. This controller adds
 * NO import logic of its own -- validation of the batch shape (an
 * array of raw rows) is the only thing done here; every per-row field
 * validation, duplicate-detection, and capability check happens inside
 * the service exactly as it already did for direct Application-layer
 * callers (e.g. existing tests).
 *
 * Deliberately no `dry_run` mode in this correction -- the original
 * 8A.12 service has no such mode, and inventing one now would be new
 * scope beyond activating the existing, already-approved service.
 */
class EmployeeImportController extends Controller
{
    public function store(Request $request, School $school, EmployeeImportService $service): JsonResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:'.EmployeeImportService::MAX_ROWS_PER_BATCH],
            'rows.*' => ['array'],
        ]);

        $result = $service->import($school, $request->user(), $validated['rows']);

        return response()->json(['data' => $result->toArray()], 201);
    }
}
