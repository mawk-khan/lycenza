<?php

namespace App\Domain\Hostel\Http\Controllers;

use App\Domain\Hostel\Infrastructure\Hostel;
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
 * Phase 10D -- Hostel directory administrative API. Mirrors
 * App\Domain\Transport\Http\Controllers\TransportRouteController's
 * shape exactly.
 */
class HostelController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        $query = Hostel::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (Hostel $h) => $this->present($h))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostels', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['required', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id']);

        $hostel = DB::transaction(function () use ($school, $validated, $request) {
            $hostel = Hostel::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'hostel.created', actor: $request->user(), subject: $hostel, metadata: [
                'hostelId' => $hostel->id,
                'code' => $hostel->code,
            ]);

            return $hostel;
        });

        return response()->json(['data' => $this->present($hostel)], 201);
    }

    public function show(School $school, string $hostel): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.view', $school);

        $model = Hostel::query()->findOrFail($hostel);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $hostel): JsonResponse
    {
        $this->authorizeCapability('hostel.directory.manage', $school);

        Hostel::query()->findOrFail($hostel);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'campus_id' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        $model = DB::transaction(function () use ($school, $hostel, $validated, $request) {
            // ORG.7D-3 (ADR 0076 §39): a Hostel's Campus is the Campus of every
            // Room, Bed and residency in it, so it cannot change once the Hostel
            // has Rooms -- history keeps the Campus it was recorded on. The row
            // lock serializes this check against Room creation, which takes the
            // Hostel row FOR SHARE.
            $model = Hostel::query()->lockForUpdate()->findOrFail($hostel);
            $campusChanges = array_key_exists('campus_id', $validated) && $validated['campus_id'] !== $model->campus_id;
            if ($campusChanges && $model->rooms()->exists()) {
                throw ValidationException::withMessages(['campus_id' => ['A Hostel\'s Campus cannot change once it has Rooms.']]);
            }

            $before = $model->only(['status', 'campus_id']);
            $model->update($validated);

            app(AuditRecorder::class)->school($school, 'hostel.updated', actor: $request->user(), subject: $model, metadata: [
                'hostelId' => $model->id,
                'statusBefore' => $before['status'],
                'statusAfter' => $validated['status'] ?? null,
                'campusBefore' => $campusChanges ? $before['campus_id'] : null,
                'campusAfter' => $campusChanges ? $validated['campus_id'] : null,
            ]);

            return $model;
        });

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
    private function present(Hostel $hostel): array
    {
        return [
            'id' => $hostel->id,
            'campusId' => $hostel->campus_id,
            'code' => $hostel->code,
            'name' => $hostel->name,
            'status' => $hostel->status,
        ];
    }
}
