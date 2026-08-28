<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Infrastructure\InventoryLocation;
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
 * Phase 10E -- Inventory Location directory administrative API.
 * Mirrors App\Domain\Hostel\Http\Controllers\HostelController's shape
 * exactly. `campus_id` is optional (docs/modules/INVENTORY.md
 * "Campus / Location ownership").
 */
class InventoryLocationController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $query = InventoryLocation::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (InventoryLocation $l) => $this->present($l))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('inventory_locations', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id'] ?? null);

        $location = DB::transaction(function () use ($school, $validated, $request) {
            $location = InventoryLocation::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'inventory.location.created', actor: $request->user(), subject: $location, metadata: [
                'locationId' => $location->id,
                'code' => $location->code,
            ]);

            return $location;
        });

        return response()->json(['data' => $this->present($location)], 201);
    }

    public function show(School $school, string $inventoryLocation): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $model = InventoryLocation::query()->findOrFail($inventoryLocation);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $inventoryLocation): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);

        $model = InventoryLocation::query()->findOrFail($inventoryLocation);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'campus_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'inventory.location.updated', actor: $request->user(), subject: $model, metadata: [
            'locationId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
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
    private function present(InventoryLocation $location): array
    {
        return [
            'id' => $location->id,
            'campusId' => $location->campus_id,
            'code' => $location->code,
            'name' => $location->name,
            'status' => $location->status,
        ];
    }
}
