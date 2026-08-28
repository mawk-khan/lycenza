<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Infrastructure\Position;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * HR Position reference data. Same reference-entity controller shape
 * as `DepartmentController` -- see that class's docblock.
 */
class PositionController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hr.positions.view', $school);

        $query = Position::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Position $p) => $this->present($p))->all()]);
    }

    public function store(Request $request, School $school, PositionService $service): JsonResponse
    {
        $this->authorizeCapability('hr.positions.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('positions', 'code')->where('school_id', $school->id)],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $position = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($position)], 201);
    }

    public function update(Request $request, School $school, string $position, PositionService $service): JsonResponse
    {
        abort_if(! Str::isUuid($position), 404);
        $this->authorizeCapability('hr.positions.manage', $school);
        $this->normalizeCodeInput($request);

        $model = Position::query()->findOrFail($position);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('positions', 'code')->where('school_id', $school->id)->ignore($model->id)],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $position, PositionService $service): JsonResponse
    {
        abort_if(! Str::isUuid($position), 404);
        $this->authorizeCapability('hr.positions.manage', $school);

        $model = Position::query()->findOrFail($position);
        $archived = $service->archive($model, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    public function reactivate(Request $request, School $school, string $position, PositionService $service): JsonResponse
    {
        abort_if(! Str::isUuid($position), 404);
        $this->authorizeCapability('hr.positions.manage', $school);

        $model = Position::query()->findOrFail($position);
        $reactivated = $service->reactivate($model, $request->user());

        return response()->json(['data' => $this->present($reactivated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Position $position): array
    {
        return [
            'id' => $position->id,
            'name' => $position->name,
            'code' => $position->code,
            'description' => $position->description,
            'status' => $position->status,
        ];
    }
}
