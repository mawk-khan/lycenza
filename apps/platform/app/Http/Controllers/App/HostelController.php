<?php

namespace App\Http\Controllers\App;

use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Http\Controllers\Controller;
use App\Models\Campus;
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
 * Phase 10D -- session-authenticated Inertia pages for the Hostel
 * directory (Hostel + its Rooms). Mirrors
 * App\Http\Controllers\App\TransportRouteController's shape exactly,
 * including nesting Room create under the Hostel show page the same
 * way Transport nests Stop create under Route show.
 */
class HostelController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = Hostel::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Hostel/Directory/Index', [
            'hostels' => $paginator->through(fn (Hostel $h) => [
                'id' => $h->id,
                'code' => $h->code,
                'name' => $h->name,
                'status' => $h->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'hostel.directory.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        return Inertia::render('App/Hostel/Directory/Create', [
            'campuses' => Campus::query()->orderBy('name')->get()
                ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostels', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['required', 'string'],
        ]);

        $hostel = DB::transaction(function () use ($school, $validated, $context) {
            $hostel = Hostel::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'hostel.created', actor: $context->actor(), subject: $hostel, metadata: [
                'hostelId' => $hostel->id,
                'code' => $hostel->code,
            ]);

            return $hostel;
        });

        return redirect('/app/hostels')->with('flash', "Hostel {$hostel->code} created.");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $hostel): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.view', $school);

        $model = Hostel::query()->findOrFail($hostel);
        $rooms = HostelRoom::query()->where('hostel_id', $model->id)->where('status', 'active')
            ->withCount(['beds' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('code')->get();

        return Inertia::render('App/Hostel/Show/Index', [
            'hostel' => ['id' => $model->id, 'code' => $model->code, 'name' => $model->name, 'status' => $model->status],
            'rooms' => $rooms->map(fn (HostelRoom $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'floorOrBlock' => $r->floor_or_block,
                'status' => $r->status,
                'bedCount' => $r->beds_count,
            ]),
            'canManage' => $capabilities->canInSchool($context->actor(), 'hostel.directory.manage', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, string $hostel): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);

        $model = Hostel::query()->findOrFail($hostel);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'hostel.updated', actor: $context->actor(), subject: $model, metadata: [
            'hostelId' => $model->id,
            'statusBefore' => $before['status'],
            'statusAfter' => $validated['status'],
        ]);

        return redirect('/app/hostels')->with('flash', 'Hostel updated.');
    }

    public function storeRoom(Request $request, TenantContext $context, string $hostel): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('hostel.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $model = Hostel::query()->findOrFail($hostel);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('hostel_rooms', 'code')->where('hostel_id', $model->id)],
            'floor_or_block' => ['nullable', 'string', 'max:64'],
        ]);

        $room = DB::transaction(function () use ($school, $model, $validated, $context) {
            $room = HostelRoom::query()->create([...$validated, 'school_id' => $school->id, 'hostel_id' => $model->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'hostel.room.created', actor: $context->actor(), subject: $room, metadata: [
                'hostelId' => $model->id,
                'hostelRoomId' => $room->id,
                'code' => $room->code,
            ]);

            return $room;
        });

        return redirect("/app/hostels/{$model->id}")->with('flash', "Room {$room->code} added.");
    }
}
