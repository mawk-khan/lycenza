<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 10B -- Transport Stop administrative API, nested under a
 * Route. Mirrors LibraryCopyController's shape (a child reference
 * entity nested under its owning parent).
 */
class TransportStopController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, string $transportRoute): JsonResponse
    {
        $this->authorizeCapability('transport.routes.view', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $query = $route->stops()->orderBy('sequence');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (TransportStop $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, School $school, string $transportRoute): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1', Rule::unique('transport_stops', 'sequence')->where('route_id', $route->id)],
            'address' => ['nullable', 'string'],
        ]);

        $stop = DB::transaction(function () use ($school, $route, $validated, $request) {
            $stop = TransportStop::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'route_id' => $route->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'transport.stop.created', actor: $request->user(), subject: $stop, metadata: [
                'transportRouteId' => $route->id,
                'name' => $stop->name,
                'sequence' => $stop->sequence,
            ]);

            return $stop;
        });

        return response()->json(['data' => $this->present($stop)], 201);
    }

    public function update(Request $request, School $school, string $transportStop): JsonResponse
    {
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportStop::query()->findOrFail($transportStop);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sequence' => ['sometimes', 'integer', 'min:1', Rule::unique('transport_stops', 'sequence')->where('route_id', $model->route_id)->ignore($model->id)],
            'address' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.stop.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportStop $stop): array
    {
        return [
            'id' => $stop->id,
            'routeId' => $stop->route_id,
            'name' => $stop->name,
            'sequence' => $stop->sequence,
            'address' => $stop->address,
            'status' => $stop->status,
        ];
    }
}
