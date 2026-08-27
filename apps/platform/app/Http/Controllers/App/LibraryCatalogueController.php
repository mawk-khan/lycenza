<?php

namespace App\Http\Controllers\App;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10A -- session-authenticated Inertia pages for the Library
 * catalogue (Title/Copy), mirroring StudentController's established
 * convention exactly: every mutation delegates through the same
 * simple create-then-audit pattern the JSON API controllers use (no
 * duplicated business logic between the two surfaces beyond this
 * checkpoint's genuinely simple validation, matching Subject/Campus's
 * own JSON-API/Inertia duplication precedent for equally simple
 * reference entities).
 */
class LibraryCatalogueController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.catalogue.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = LibraryTitle::query()->where('status', 'active')->withCount('copies')->orderBy('title');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('title', 'ilike', $term)->orWhere('author', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Library/Catalogue/Index', [
            'titles' => $paginator->through(fn (LibraryTitle $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'author' => $t->author,
                'copiesCount' => $t->copies_count,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'library.catalogue.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.catalogue.manage', $school);

        return Inertia::render('App/Library/Catalogue/Create');
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.catalogue.manage', $school);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:32'],
        ]);

        $title = DB::transaction(function () use ($school, $validated, $context) {
            $title = LibraryTitle::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'library.title.created', actor: $context->actor(), subject: $title, metadata: [
                'title' => $title->title,
            ]);

            return $title;
        });

        return redirect("/app/library/titles/{$title->id}");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $libraryTitle): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.catalogue.view', $school);

        $title = LibraryTitle::query()->with('copies.activeLoan.student')->findOrFail($libraryTitle);

        return Inertia::render('App/Library/Catalogue/Show', [
            'title' => [
                'id' => $title->id,
                'title' => $title->title,
                'author' => $title->author,
                'isbn' => $title->isbn,
                'status' => $title->status,
            ],
            'copies' => $title->copies->map(fn (LibraryCopy $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'status' => $c->status,
                'available' => $c->isAvailable(),
                'activeLoan' => $c->activeLoan ? [
                    'id' => $c->activeLoan->id,
                    'studentName' => trim($c->activeLoan->student->first_name.' '.$c->activeLoan->student->last_name),
                    'dueAt' => $c->activeLoan->due_at->toDateString(),
                ] : null,
            ])->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'library.catalogue.manage', $school),
        ]);
    }

    public function storeCopy(Request $request, TenantContext $context, string $libraryTitle): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.catalogue.manage', $school);
        $this->normalizeCodeInput($request);

        $title = LibraryTitle::query()->findOrFail($libraryTitle);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('library_copies', 'code')->where('school_id', $school->id)],
            'campus_id' => ['nullable', 'string'],
        ]);

        if (! empty($validated['campus_id']) && Campus::query()->where('id', $validated['campus_id'])->doesntExist()) {
            throw ValidationException::withMessages(['campus_id' => ['This Campus does not belong to this School.']]);
        }

        $copy = DB::transaction(function () use ($school, $title, $validated, $context) {
            $copy = LibraryCopy::query()->create([
                'school_id' => $school->id,
                'library_title_id' => $title->id,
                'campus_id' => $validated['campus_id'] ?? null,
                'code' => $validated['code'],
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'library.copy.created', actor: $context->actor(), subject: $copy, metadata: [
                'libraryTitleId' => $title->id,
                'code' => $copy->code,
            ]);

            return $copy;
        });

        return redirect("/app/library/titles/{$title->id}")->with('flash', "Copy {$copy->code} registered.");
    }
}
