<?php

namespace App\Domain\Library\Http\Controllers;

use App\Domain\Library\Application\LibraryCirculationAccess;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
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
 *
 * ORG.7D-5 (ADR 0076 §41): `library.circulation.*` are Campus-adopted --
 * every action resolves its CampusScope and reads or writes through
 * LibraryCirculationAccess (a loan by its frozen Campus; out of scope =
 * NOT FOUND); a Campus-scoped checkout is proven under lock
 * (LibraryCirculationCampusGuard).
 */
class LibraryLoanController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, School $school, LibraryCirculationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('library.circulation.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'returned'])],
            'student_id' => ['sometimes', 'string'],
        ]);

        // The student_id filter only narrows the scoped set.
        $query = $access->loans($scope)->with(['copy.title', 'student'])->orderByDesc('checked_out_at');

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

    public function store(Request $request, School $school, LibraryLoanService $service, LibraryCirculationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $validated = $request->validate([
            'library_copy_id' => ['required', 'string'],
            'student_id' => ['required', 'string'],
            'due_at' => ['required', 'date'],
        ]);

        $copy = $access->copy($scope, $validated['library_copy_id']);
        $student = $access->borrower($scope, $validated['student_id']);

        $loan = $service->checkout($copy, $student, Carbon::parse($validated['due_at']), $request->user(), $access->writeGuard($school, $scope));

        return response()->json(['data' => $this->present($loan->load(['copy.title', 'student']))], 201);
    }

    public function show(School $school, string $libraryLoan, LibraryCirculationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('library.circulation.view', $school);

        $model = $access->loan($scope, $libraryLoan)->load(['copy.title', 'student']);

        return response()->json(['data' => $this->present($model)]);
    }

    public function checkIn(Request $request, School $school, string $libraryLoan, LibraryLoanService $service, LibraryCirculationAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $model = $access->loan($scope, $libraryLoan);

        $loan = $service->checkIn($model, $request->user(), $access->writeGuard($school, $scope));

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
