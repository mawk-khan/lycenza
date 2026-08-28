<?php

namespace App\Domain\Visitor\Http\Controllers;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 10C -- Visitor directory administrative API. Mirrors
 * App\Domain\Transport\Http\Controllers\TransportVehicleController's
 * shape exactly. A short, direct create-then-audit-then-event-free
 * block inline in the controller is intentional here (root CLAUDE.md
 * rule 76) -- Visitor directory CRUD has no invariant beyond
 * uniqueness-free basic fields, unlike the Visit lifecycle which earns
 * its own Application service.
 */
class VisitorController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('visitor.directory.view', $school);

        $query = Visitor::query()->orderBy('full_name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('full_name', 'ilike', $term)->orWhere('phone', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (Visitor $v) => $this->present($v))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('visitor.directory.manage', $school);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $visitor = DB::transaction(function () use ($school, $validated, $request) {
            $visitor = Visitor::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'visitor.created', actor: $request->user(), subject: $visitor, metadata: [
                'visitorId' => $visitor->id,
            ]);

            return $visitor;
        });

        return response()->json(['data' => $this->present($visitor)], 201);
    }

    public function show(School $school, string $visitor): JsonResponse
    {
        $this->authorizeCapability('visitor.directory.view', $school);

        $model = Visitor::query()->findOrFail($visitor);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $visitor): JsonResponse
    {
        $this->authorizeCapability('visitor.directory.manage', $school);

        $model = Visitor::query()->findOrFail($visitor);

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'visitor.updated', actor: $request->user(), subject: $model, metadata: [
            'visitorId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Visitor $visitor): array
    {
        return [
            'id' => $visitor->id,
            'fullName' => $visitor->full_name,
            'phone' => $visitor->phone,
            'status' => $visitor->status,
        ];
    }
}
