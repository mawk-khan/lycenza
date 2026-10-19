<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Application\LibraryCopyService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request, School $school, string $libraryTitle, LibraryCopyService $copies): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);
        $this->normalizeCodeInput($request);

        $title = LibraryTitle::query()->findOrFail($libraryTitle);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('library_copies', 'code')->where('school_id', $school->id)],
            'campus_id' => ['nullable', 'string'],
        ]);

        // ORG.7D-5 (ADR 0076 §41): an active Campus of this School, or none.
        $copy = $copies->create($school, $title, $validated['code'], $validated['campus_id'] ?? null, $request->user());

        return response()->json(['data' => $this->present($copy)], 201);
    }

    public function update(Request $request, School $school, string $libraryCopy, LibraryCopyService $copies): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);

        $model = LibraryCopy::query()->findOrFail($libraryCopy);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'campus_id' => ['sometimes', 'nullable', 'string'],
        ]);

        // ORG.7D-5 (ADR 0076 §41): locked, refused while on loan, audited in the same transaction.
        return response()->json(['data' => $this->present($copies->update($school, $model->id, $validated, $request->user()))]);
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
