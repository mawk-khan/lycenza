<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 10A -- Library catalogue (Title) administrative API. A short,
 * direct create-then-audit block inline in a controller action,
 * exactly matching SubjectController's established pattern for a
 * genuinely simple reference entity (CLAUDE.md rule 76's carve-out --
 * no real invariants beyond validation here, so no dedicated
 * Application service). LibraryLoanService (circulation, real
 * invariants) is the exception to this, not the rule.
 */
class LibraryTitleController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.view', $school);

        $query = LibraryTitle::query()->orderBy('title');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('title', 'ilike', $term)->orWhere('author', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (LibraryTitle $t) => $this->present($t))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32'],
        ]);

        $title = DB::transaction(function () use ($school, $validated, $request) {
            $title = LibraryTitle::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'library.title.created', actor: $request->user(), subject: $title, metadata: [
                'title' => $title->title,
            ]);

            return $title;
        });

        return response()->json(['data' => $this->present($title)], 201);
    }

    public function show(School $school, string $libraryTitle): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.view', $school);

        $model = LibraryTitle::query()->findOrFail($libraryTitle);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $libraryTitle): JsonResponse
    {
        $this->authorizeCapability('library.catalogue.manage', $school);

        $model = LibraryTitle::query()->findOrFail($libraryTitle);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'author' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isbn' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'library.title.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LibraryTitle $title): array
    {
        return [
            'id' => $title->id,
            'title' => $title->title,
            'author' => $title->author,
            'isbn' => $title->isbn,
            'status' => $title->status,
        ];
    }
}
