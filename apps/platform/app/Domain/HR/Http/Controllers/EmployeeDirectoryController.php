<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeDirectoryEntry;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8A.14 -- the sole HTTP transport for the 8A.8 Employee
 * Directory. Read-only: a thin adapter over
 * `EmployeeDirectoryService::search()`, never a second query
 * implementation. Deliberately carries NO `capability:` route
 * middleware -- `EmployeeDirectoryService::search()` already performs
 * its own `hr.employees.view` check against the real authenticated
 * actor before running any query (docs/modules/HR.md 8A.10 as-built);
 * adding a route-level capability check here would either duplicate
 * that exact same check (redundant) or risk drifting from it
 * (dangerous) -- see docs/modules/HR.md's 8A.14 "no divergent
 * capability matrix" decision.
 */
class EmployeeDirectoryController extends Controller
{
    public function index(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Phase 8A.15: `uuid` format is enforced HERE, not left to
            // flow through to EmployeeDirectoryService's `ea.campus_id`/
            // `ea.department_id`/`ea.position_id` comparisons -- those
            // columns are UUID-typed, so PostgreSQL raises a raw
            // `invalid input syntax for type uuid` QueryException (a
            // 500, not a safe empty result) for a non-UUID string.
            // This was a REAL bug found during this checkpoint's own
            // abuse-input testing, not a hypothetical one -- a
            // nonexistent-but-still-UUID-shaped id already correctly
            // yields zero results via the existing tenant-safe query,
            // unaffected by this fix.
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'position_id' => ['sometimes', 'nullable', 'uuid'],
            'sort' => ['sometimes', 'nullable', 'string'],
            'direction' => ['sometimes', 'nullable', 'string'],
            'include_archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // EmployeeDirectoryQuery's own constructor is the allow-list
        // authority for sort/direction/per_page (falls back to a safe
        // default for an unrecognized sort column, clamps per_page to
        // EmployeeDirectoryService::MAX_PER_PAGE) -- this controller
        // never builds a raw orderBy()/query itself (checkpoint 8A.14
        // section 32: no sort injection surface at the transport
        // layer, by construction).
        $query = new EmployeeDirectoryQuery(
            search: $validated['search'] ?? null,
            campusId: $validated['campus_id'] ?? null,
            departmentId: $validated['department_id'] ?? null,
            positionId: $validated['position_id'] ?? null,
            includeArchived: $request->boolean('include_archived'),
            sort: $validated['sort'] ?? null,
            direction: $validated['direction'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? EmployeeDirectoryQuery::DEFAULT_PER_PAGE),
        );

        $paginator = app(EmployeeDirectoryService::class)->search($school, $query, $request->user());

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (EmployeeDirectoryEntry $entry) => $entry->toArray())->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
