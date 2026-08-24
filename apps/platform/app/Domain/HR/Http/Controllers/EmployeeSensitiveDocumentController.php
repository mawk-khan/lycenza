<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeProfileDocumentEntry;
use App\Domain\HR\Application\EmployeeSensitiveDocumentReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8A.14 -- the sole HTTP transport for Highly Sensitive
 * `EmployeeDocument` metadata, kept as a SEPARATE route/controller from
 * `EmployeeProfileController` on purpose -- the general Profile
 * endpoint never includes `classification_tier = highly_sensitive`
 * rows (8A.9's own acceptance gate), and this checkpoint does not add
 * an `?include_sensitive=true` switch to it (checkpoint 8A.14 section
 * 12). Thin adapter over
 * `EmployeeSensitiveDocumentReadService::forEmployee()`, which already
 * owns the `hr.employees.sensitive.view` capability check, the
 * tenant-safe null-for-not-found-or-cross-School resolution, and the
 * exactly-once `hr.employee_document.sensitive_viewed` audit write for
 * a non-empty read -- this controller performs none of that itself
 * (no duplicate audit event, checkpoint section 15).
 */
class EmployeeSensitiveDocumentController extends Controller
{
    public function index(Request $request, School $school, string $employee): JsonResponse
    {
        $entries = app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee, $request->user());

        abort_if($entries === null, 404);

        return response()->json([
            'data' => array_map(fn (EmployeeProfileDocumentEntry $entry) => $entry->toArray(), $entries),
        ]);
    }
}
