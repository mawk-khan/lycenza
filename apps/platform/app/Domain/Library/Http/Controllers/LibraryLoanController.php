<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Phase 10A -- Library circulation administrative API. store() (the
 * consequential checkout mutation the checkpoint brief specifically
 * flags for idempotency evaluation) carries the `idempotent`
 * middleware in routes/api.php -- see LibraryLoanService's docblock
 * for the concurrency guarantee this endpoint delegates to.
 */
class LibraryLoanController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('library.circulation.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'returned'])],
            'student_id' => ['sometimes', 'string'],
        ]);

        $query = LibraryLoan::query()->with(['copy.title', 'student'])->orderByDesc('checked_out_at');

        $query->where('status', $validated['status'] ?? 'active');

        if (isset($validated['student_id'])) {
            $query->where('student_id', $validated['student_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (LibraryLoan $l) => $this->present($l))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, LibraryLoanService $service): JsonResponse
    {
        $this->authorizeCapability('library.circulation.manage', $school);

        $validated = $request->validate([
            'library_copy_id' => ['required', 'string'],
            'student_id' => ['required', 'string'],
            'due_at' => ['required', 'date'],
        ]);

        $copy = LibraryCopy::query()->findOrFail($validated['library_copy_id']);
        $student = Student::query()->findOrFail($validated['student_id']);

        $loan = $service->checkout($copy, $student, Carbon::parse($validated['due_at']), $request->user());

        return response()->json(['data' => $this->present($loan->load(['copy.title', 'student']))], 201);
    }

    public function show(School $school, string $libraryLoan): JsonResponse
    {
        $this->authorizeCapability('library.circulation.view', $school);

        $model = LibraryLoan::query()->with(['copy.title', 'student'])->findOrFail($libraryLoan);

        return response()->json(['data' => $this->present($model)]);
    }

    public function checkIn(Request $request, School $school, string $libraryLoan, LibraryLoanService $service): JsonResponse
    {
        $this->authorizeCapability('library.circulation.manage', $school);

        $model = LibraryLoan::query()->findOrFail($libraryLoan);

        $loan = $service->checkIn($model, $request->user());

        return response()->json(['data' => $this->present($loan->load(['copy.title', 'student']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LibraryLoan $loan): array
    {
        return [
            'id' => $loan->id,
            'status' => $loan->status,
            'checkedOutAt' => $loan->checked_out_at->toIso8601String(),
            'dueAt' => $loan->due_at->toIso8601String(),
            'checkedInAt' => $loan->checked_in_at?->toIso8601String(),
            'isOverdue' => $loan->isOverdue(),
            'copy' => [
                'id' => $loan->copy->id,
                'code' => $loan->copy->code,
                'title' => $loan->copy->title->title,
            ],
            'student' => [
                'id' => $loan->student->id,
                'studentNumber' => $loan->student->student_number,
                'firstName' => $loan->student->first_name,
                'lastName' => $loan->student->last_name,
            ],
        ];
    }
}
