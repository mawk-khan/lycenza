<?php

namespace App\Domain\Hostel\Http\Controllers;

use App\Domain\Hostel\Infrastructure\HostelBed;
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
 * Phase 10D -- HostelBed administrative API, nested under a Room.
 * Shares the `hostel.directory.*` capability with Hostel/HostelRoom.
 * No `student_id`/occupant column exists here -- `occupied` in the
 * presenter is always derived from `activeResidency()`, never stored.
 */
class HostelBedController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, string $hostelRoom): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        $room = HostelRoom::query()->findOrFail($hostelRoom);

        $query = HostelBed::query()->where('hostel_room_id', $room->id)->with('activeResidency')->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (HostelBed $b) => $this->present($b))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, string $hostelRoom): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $room = HostelRoom::query()->findOrFail($hostelRoom);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostel_beds', 'code')->where('hostel_room_id', $room->id)],
        ]);

        $bed = DB::transaction(function () use ($school, $room, $validated, $request) {
            $bed = HostelBed::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'hostel_room_id' => $room->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'hostel.bed.created', actor: $request->user(), subject: $bed, metadata: [
                'hostelRoomId' => $room->id,
                'hostelBedId' => $bed->id,
                'code' => $bed->code,
            ]);

            return $bed;
        });

        return response()->json(['data' => $this->present($bed)], 201);
    }

    public function update(Request $request, School $school, string $hostelBed): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);

        $model = HostelBed::query()->findOrFail($hostelBed);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'hostel.bed.updated', actor: $request->user(), subject: $model, metadata: [
            'hostelRoomId' => $model->hostel_room_id,
            'hostelBedId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HostelBed $bed): array
    {
        return [
            'id' => $bed->id,
            'hostelRoomId' => $bed->hostel_room_id,
            'code' => $bed->code,
            'status' => $bed->status,
            'occupied' => $bed->relationLoaded('activeResidency')
                ? $bed->activeResidency !== null
                : $bed->activeResidency()->exists(),
        ];
    }
}
