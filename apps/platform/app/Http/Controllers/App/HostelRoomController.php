<?php

namespace App\Http\Controllers\App;

use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10D -- session-authenticated Inertia pages for a HostelRoom's
 * Beds. Occupancy shown per Bed is always derived from
 * `activeResidency()`, never a stored column.
 */
class HostelRoomController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $hostelRoom): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.view', $school);

        $model = HostelRoom::query()->with('hostel')->findOrFail($hostelRoom);
        $beds = HostelBed::query()->where('hostel_room_id', $model->id)->where('status', 'active')
            ->with('activeResidency.student')->orderBy('code')->get();

        return Inertia::render('App/Hostel/Show/Room', [
            'room' => ['id' => $model->id, 'code' => $model->code, 'floorOrBlock' => $model->floor_or_block, 'status' => $model->status],
            'hostel' => ['id' => $model->hostel->id, 'code' => $model->hostel->code, 'name' => $model->hostel->name],
            'beds' => $beds->map(fn (HostelBed $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'status' => $b->status,
                'occupantName' => $b->activeResidency
                    ? trim($b->activeResidency->student->first_name.' '.$b->activeResidency->student->last_name)
                    : null,
            ]),
            'canManage' => $capabilities->canInSchool($context->actor(), 'hostel.directory.manage', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, string $hostelRoom): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        $model = HostelRoom::query()->findOrFail($hostelRoom);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'hostel.room.updated', actor: $context->actor(), subject: $model, metadata: [
            'hostelId' => $model->hostel_id,
            'hostelRoomId' => $model->id,
            'statusBefore' => $before['status'],
            'statusAfter' => $validated['status'],
        ]);

        return redirect("/app/hostel-rooms/{$model->id}")->with('flash', 'Room updated.');
    }

    public function storeBed(Request $request, TenantContext $context, string $hostelRoom): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $model = HostelRoom::query()->findOrFail($hostelRoom);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostel_beds', 'code')->where('hostel_room_id', $model->id)],
        ]);

        $bed = DB::transaction(function () use ($school, $model, $validated, $context) {
            $bed = HostelBed::query()->create([...$validated, 'school_id' => $school->id, 'hostel_room_id' => $model->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'hostel.bed.created', actor: $context->actor(), subject: $bed, metadata: [
                'hostelRoomId' => $model->id,
                'hostelBedId' => $bed->id,
                'code' => $bed->code,
            ]);

            return $bed;
        });

        return redirect("/app/hostel-rooms/{$model->id}")->with('flash', "Bed {$bed->code} added.");
    }

    public function updateBed(Request $request, TenantContext $context, string $hostelBed): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        $model = HostelBed::query()->findOrFail($hostelBed);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'hostel.bed.updated', actor: $context->actor(), subject: $model, metadata: [
            'hostelRoomId' => $model->hostel_room_id,
            'hostelBedId' => $model->id,
            'statusBefore' => $before['status'],
            'statusAfter' => $validated['status'],
        ]);

        return redirect("/app/hostel-rooms/{$model->hostel_room_id}")->with('flash', 'Bed updated.');
    }
}
