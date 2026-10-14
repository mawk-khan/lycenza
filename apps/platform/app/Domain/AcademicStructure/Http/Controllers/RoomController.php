<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Application\RoomAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 34-36, 52. Nested under a specific Campus for
 * creation/listing (a Room always belongs to exactly one Campus); a
 * single Room's show/update is resolved by its own id, School-scoped.
 */
class RoomController extends Controller
{
    use AuthorizesCapability;

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

    public function store(Request $request, School $school, string $campus, RoomAdministrationService $rooms): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $room = $rooms->create($school, $campus, $request->all(), $request->user());

        return response()->json(['data' => $this->present($room)], 201);
    }

    public function show(School $school, string $room): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = Room::query()->findOrFail($room);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $room, RoomAdministrationService $rooms): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $rooms->update($school, $room, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
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
