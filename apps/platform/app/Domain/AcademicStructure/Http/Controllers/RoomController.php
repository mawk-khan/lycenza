<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0D sections 34-36, 52. Nested under a specific Campus for
 * creation/listing (a Room always belongs to exactly one Campus); a
 * single Room's show/update is resolved by its own id, School-scoped.
 */
class RoomController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, string $campus): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $campusModel = Campus::query()->findOrFail($campus);

        $query = Room::query()->where('campus_id', $campusModel->id)->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Room $r) => $this->present($r))->all()]);
    }

    public function store(Request $request, School $school, string $campus): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $campusModel = Campus::query()->findOrFail($campus);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('rooms')->where('school_id', $school->id)->where('campus_id', $campusModel->id)],
            'room_type' => ['sometimes', Rule::in(['classroom', 'laboratory', 'auditorium', 'library', 'sports', 'other'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $room = Room::query()->create([...$validated, 'school_id' => $school->id, 'campus_id' => $campusModel->id]);

        app(AuditRecorder::class)->school($school, 'room.created', actor: $request->user(), subject: $room, metadata: [
            'name' => $room->name,
            'code' => $room->code,
            'campusId' => $campusModel->id,
        ]);

        return response()->json(['data' => $this->present($room)], 201);
    }

    public function show(School $school, string $room): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = Room::query()->findOrFail($room);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $room): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = Room::query()->findOrFail($room);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('rooms')->where('school_id', $school->id)->where('campus_id', $model->campus_id)->ignore($model->id)],
            'room_type' => ['sometimes', Rule::in(['classroom', 'laboratory', 'auditorium', 'library', 'sports', 'other'])],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'room.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Room $room): array
    {
        return [
            'id' => $room->id,
            'campusId' => $room->campus_id,
            'name' => $room->name,
            'code' => $room->code,
            'roomType' => $room->room_type,
            'capacity' => $room->capacity,
            'status' => $room->status,
        ];
    }
}
