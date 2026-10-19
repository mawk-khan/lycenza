<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportRouteService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10B -- Transport Route (+ Stop) administrative API. A short,
 * direct create-then-audit block inline in a controller action,
 * mirroring LibraryTitleController's established pattern for a
 * genuinely simple reference entity (CLAUDE.md rule 76's carve-out).
 *
 * ORG.7D-5 (ADR 0076 §41): `transport.routes.view` is Campus-adopted --
 * index/show read through TransportRiderAccess (a Campus-scoped actor sees
 * only Campus-bound routes in scope; a NULL-Campus route is whole-School
 * only). Creating and changing a route (`transport.routes.manage`) stays
 * whole-School, through TransportRouteService.
 */
class TransportRouteController extends Controller
{
    use AuthorizesCampusScope, AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.routes.view', $school);

        $query = $access->routes($scope)->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.addcslashes((string) $request->string('search'), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (TransportRoute $r) => $this->present($r))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, TransportRouteService $routes): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_routes', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'string'],
        ]);

        // ORG.7D-5: an active Campus of this School, or none (School-shared).
        $route = $routes->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($route)], 201);
    }

    public function show(School $school, string $transportRoute, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.routes.view', $school);

        $model = $access->route($scope, $transportRoute)->load('stops');

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $transportRoute, TransportRouteService $routes): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'campus_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        // ORG.7D-5: locked; the Campus is fixed once the route is used.
        return response()->json(['data' => $this->present($routes->update($school, $model->id, $validated, $request->user()))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportRoute $route): array
    {
        return [
            'id' => $route->id,
            'campusId' => $route->campus_id,
            'code' => $route->code,
            'name' => $route->name,
            'description' => $route->description,
            'status' => $route->status,
        ];
    }
}
