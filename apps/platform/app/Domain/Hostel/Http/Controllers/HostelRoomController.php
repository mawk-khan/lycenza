<?php

namespace App\Domain\Hostel\Http\Controllers;

use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 10D -- HostelRoom administrative API, nested under a Hostel.
 * Shares the `hostel.directory.*` capability with Hostel/HostelBed --
 * a Room has no independent meaning outside its Hostel, matching
 * Library's Copy-under-Title / Transport's Stop-under-Route precedent.
 */
class HostelRoomController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, string $hostel): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        $hostelModel = Hostel::query()->findOrFail($hostel);

        $query = HostelRoom::query()->where('hostel_id', $hostelModel->id)->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (HostelRoom $r) => $this->present($r))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, string $hostel): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $hostelModel = Hostel::query()->findOrFail($hostel);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostel_rooms', 'code')->where('hostel_id', $hostelModel->id)],
            'floor_or_block' => ['nullable', 'string', 'max:64'],
        ]);

        $room = DB::transaction(function () use ($school, $hostelModel, $validated, $request) {
            $room = HostelRoom::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'hostel_id' => $hostelModel->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'hostel.room.created', actor: $request->user(), subject: $room, metadata: [
                'hostelId' => $hostelModel->id,
                'hostelRoomId' => $room->id,
                'code' => $room->code,
            ]);

            return $room;
        });

        return response()->json(['data' => $this->present($room)], 201);
    }

    public function update(Request $request, School $school, string $hostelRoom): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);

        $model = HostelRoom::query()->findOrFail($hostelRoom);

        $validated = $request->validate([
            'floor_or_block' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'hostel.room.updated', actor: $request->user(), subject: $model, metadata: [
            'hostelId' => $model->hostel_id,
            'hostelRoomId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HostelRoom $room): array
    {
        return [
            'id' => $room->id,
            'hostelId' => $room->hostel_id,
            'code' => $room->code,
            'floorOrBlock' => $room->floor_or_block,
            'status' => $room->status,
        ];
    }
}
