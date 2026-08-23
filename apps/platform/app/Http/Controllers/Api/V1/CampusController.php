<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campuses\Events\CampusCreated;
use App\Domain\Campuses\Events\CampusUpdated;
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

/**
 * Phase 0D sections 9-10, 52. Campus already existed as tenant-owned
 * data since Phase 0B (App\Models\Campus) -- this is genuine
 * administration built around it, not a parallel abstraction.
 */
class CampusController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('school.campuses.view', $school);

        $query = Campus::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Campus $c) => $this->present($c))->all()]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('school.campuses.manage', $school);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        $campus = DB::transaction(function () use ($school, $validated, $request) {
            $campus = Campus::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'campus.created', actor: $request->user(), subject: $campus, metadata: [
                'name' => $campus->name,
                'code' => $campus->code,
            ]);

            event(new CampusCreated($school->id, $campus->id, $campus->name, $campus->code));

            return $campus;
        });

        return response()->json(['data' => $this->present($campus)], 201);
    }

    public function show(School $school, string $campus): JsonResponse
    {
        $this->authorizeCapability('school.campuses.view', $school);

        $model = Campus::query()->findOrFail($campus);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $campus): JsonResponse
    {
        $this->authorizeCapability('school.campuses.manage', $school);

        $model = Campus::query()->findOrFail($campus);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('campuses')->where('school_id', $school->id)->ignore($model->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));

        DB::transaction(function () use ($school, $model, $validated, $before, $request) {
            $model->update($validated);

            app(AuditRecorder::class)->school($school, 'campus.updated', actor: $request->user(), subject: $model, metadata: [
                'before' => $before,
                'after' => $validated,
            ]);

            event(new CampusUpdated($school->id, $model->id, array_keys($validated)));
        });

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Campus $campus): array
    {
        return [
            'id' => $campus->id,
            'name' => $campus->name,
            'code' => $campus->code,
            'status' => $campus->status,
            'phone' => $campus->phone,
            'email' => $campus->email,
            'address' => $campus->address,
        ];
    }
}
