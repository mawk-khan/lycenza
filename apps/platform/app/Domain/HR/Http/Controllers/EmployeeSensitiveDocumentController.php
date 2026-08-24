<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeProfileDocumentEntry;
use App\Domain\HR\Application\EmployeeSensitiveDocumentReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        // Phase 8A.15: reject a malformed (non-UUID) id as the same
        // tenant-safe 404 a nonexistent/cross-School Employee already
        // produces -- see EmployeeProfileController's identical fix
        // for the raw-500 this otherwise causes.
        abort_if(! Str::isUuid($employee), 404);

        $entries = app(EmployeeSensitiveDocumentReadService::class)->forEmployee($school, $employee, $request->user());

        abort_if($entries === null, 404);

        return response()->json([
            'data' => array_map(fn (EmployeeProfileDocumentEntry $entry) => $entry->toArray(), $entries),
        ]);
    }
}
