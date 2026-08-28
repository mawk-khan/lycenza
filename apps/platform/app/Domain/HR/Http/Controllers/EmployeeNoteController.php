<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3, alongside item 5's EmployeeNote
 * entity build-out) -- HTTP mutation transport for an Employee's
 * Restricted/Confidential HR-authored notes. `author_user_id` is never
 * caller-suppliable -- the service always derives it from the real
 * authenticated actor.
 */
class EmployeeNoteController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeNoteService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'classification_tier' => ['sometimes', Rule::in(EmployeeNote::CLASSIFICATION_TIERS)],
        ]);

        $note = $service->add($model, $validated, $request->user());

        return response()->json(['data' => $this->present($note)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $note, EmployeeNoteService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($note), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $noteModel = EmployeeNote::query()->findOrFail($note);

        $validated = $request->validate([
            'body' => ['sometimes', 'string'],
            'classification_tier' => ['sometimes', Rule::in(EmployeeNote::CLASSIFICATION_TIERS)],
        ]);

        $updated = $service->update($employeeModel, $noteModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, School $school, string $employee, string $note, EmployeeNoteService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($note), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $noteModel = EmployeeNote::query()->findOrFail($note);

        $service->remove($employeeModel, $noteModel, $request->user());

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeNote $note): array
    {
        return [
            'id' => $note->id,
            'employeeId' => $note->employee_id,
            'authorUserId' => $note->author_user_id,
            'body' => $note->body,
            'classificationTier' => $note->classification_tier,
            'createdAt' => $note->created_at?->toIso8601String(),
            'updatedAt' => $note->updated_at?->toIso8601String(),
        ];
    }
}
