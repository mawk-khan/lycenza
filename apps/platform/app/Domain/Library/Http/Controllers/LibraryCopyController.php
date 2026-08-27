<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
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
 * Phase 10A -- Library catalogue (physical Copy) administrative API.
 * Mirrors LibraryTitleController's shape exactly.
 */
class LibraryCopyController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, string $libraryTitle): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.view', $school);

        $title = LibraryTitle::query()->findOrFail($libraryTitle);

        $query = $title->copies()->with('activeLoan')->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (LibraryCopy $c) => $this->present($c))->all()]);
    }

    public function store(Request $request, School $school, string $libraryTitle): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);
        $this->normalizeCodeInput($request);

        $title = LibraryTitle::query()->findOrFail($libraryTitle);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('library_copies', 'code')->where('school_id', $school->id)],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id'] ?? null);

        $copy = DB::transaction(function () use ($school, $title, $validated, $request) {
            $copy = LibraryCopy::query()->create([
                'school_id' => $school->id,
                'library_title_id' => $title->id,
                'campus_id' => $validated['campus_id'] ?? null,
                'code' => $validated['code'],
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'library.copy.created', actor: $request->user(), subject: $copy, metadata: [
                'libraryTitleId' => $title->id,
                'code' => $copy->code,
            ]);

            return $copy;
        });

        return response()->json(['data' => $this->present($copy)], 201);
    }

    public function update(Request $request, School $school, string $libraryCopy): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);

        $model = LibraryCopy::query()->findOrFail($libraryCopy);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'campus_id' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'library.copy.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

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
    private function present(LibraryCopy $copy): array
    {
        return [
            'id' => $copy->id,
            'libraryTitleId' => $copy->library_title_id,
            'campusId' => $copy->campus_id,
            'code' => $copy->code,
            'status' => $copy->status,
            'available' => $copy->isAvailable(),
        ];
    }
}
