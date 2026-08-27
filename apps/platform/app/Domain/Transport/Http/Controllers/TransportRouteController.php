<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10B -- Transport Route (+ Stop) administrative API. A short,
 * direct create-then-audit block inline in a controller action,
 * mirroring LibraryTitleController's established pattern for a
 * genuinely simple reference entity (CLAUDE.md rule 76's carve-out).
 */
class TransportRouteController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('transport.routes.view', $school);

        $query = TransportRoute::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
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

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_routes', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id'] ?? null);

        $route = DB::transaction(function () use ($school, $validated, $request) {
            $route = TransportRoute::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'transport.route.created', actor: $request->user(), subject: $route, metadata: [
                'code' => $route->code,
                'name' => $route->name,
            ]);

            return $route;
        });

        return response()->json(['data' => $this->present($route)], 201);
    }

    public function show(School $school, string $transportRoute): JsonResponse
    {
        $this->authorizeCapability('transport.routes.view', $school);

        $model = TransportRoute::query()->with('stops')->findOrFail($transportRoute);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $transportRoute): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'campus_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.route.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    private function assertCampusBelongsToSchool(School $school, ?string $campusId): void
    {
        if ($campusId === null) {
            return;
        }

        if (Campus::query()->where('id', $campusId)->doesntExist()) {
            throw ValidationException::withMessages(['campus_id' => ['This Campus does not belong to this School.']]);
        }
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
