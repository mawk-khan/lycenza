<?php

namespace App\Http\Controllers\App;

use App\Domain\Library\Application\Exceptions\ConcurrentCheckoutConflictException;
use App\Domain\Library\Application\Exceptions\CopyNotAvailableException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10A -- session-authenticated Inertia pages for Library
 * circulation. Every mutation delegates to
 * App\Domain\Library\Application\LibraryLoanService -- the exact same
 * service the JSON API controller uses, never duplicated here (matches
 * StudentController/StudentService's established split). Domain
 * exceptions a librarian can plausibly trigger via this form are
 * translated to ValidationException::withMessages(...), the same
 * pattern StudentController/GuardianController already establish for
 * Inertia's client-side form-error handling.
 */
class LibraryCirculationController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.view', $school);

        $loans = LibraryLoan::query()
            ->with(['copy.title', 'student'])
            ->where('status', 'active')
            ->orderBy('due_at')
            ->paginate(20);

        return Inertia::render('App/Library/Circulation/Index', [
            'loans' => $loans->through(fn (LibraryLoan $l) => $this->presentLoan($l)),
            'canManage' => $capabilities->canInSchool($context->actor(), 'library.circulation.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        return Inertia::render('App/Library/Circulation/Create');
    }

    /**
     * Live JSON search endpoints for the checkout form (fetched from
     * Vue, not full Inertia pages), mirroring
     * StudentGuardianRelationshipController::searchGuardians()'s exact
     * pattern -- including its authorization call, which an earlier
     * revision of this method omitted (caught in this checkpoint's own
     * security review, docs/modules/LIBRARY.md "Security review"):
     * without it, any authenticated School member -- regardless of
     * Library capability -- could enumerate Student name/number
     * (Sensitive-tier, docs/security/DATA-CLASSIFICATION.md) through
     * this endpoint. Gated on `.manage`, not `.view`, since both
     * search endpoints exist solely to feed the checkout form.
     */
    public function searchAvailableCopies(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        $term = (string) $request->query('q', '');

        $copies = LibraryCopy::query()
            ->with('title')
            ->where('status', 'active')
            ->whereDoesntHave('activeLoan')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%")
                ->orWhereHas('title', fn ($t) => $t->where('title', 'ilike', "%{$term}%")))
            ->limit(10)
            ->get();

        return response()->json(['data' => $copies->map(fn (LibraryCopy $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'title' => $c->title->title,
        ])->all()]);
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        $term = (string) $request->query('q', '');

        $students = Student::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('student_number', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'id' => $s->id,
            'studentNumber' => $s->student_number,
            'name' => trim($s->first_name.' '.$s->last_name),
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, LibraryLoanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        $validated = $request->validate([
            'library_copy_id' => ['required', 'string'],
            'student_id' => ['required', 'string'],
            'due_at' => ['required', 'date'],
        ]);

        $copy = LibraryCopy::query()->findOrFail($validated['library_copy_id']);
        $student = Student::query()->findOrFail($validated['student_id']);

        try {
            $service->checkout($copy, $student, Carbon::parse($validated['due_at']), $context->actor());
        } catch (StudentNotEligibleException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (CopyNotAvailableException|ConcurrentCheckoutConflictException $e) {
            throw ValidationException::withMessages(['library_copy_id' => [$e->getMessage()]]);
        }

        return redirect('/app/library/circulation')->with('flash', 'Item checked out.');
    }

    public function checkIn(TenantContext $context, LibraryLoanService $service, string $libraryLoan): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        $loan = LibraryLoan::query()->findOrFail($libraryLoan);

        try {
            $service->checkIn($loan, $context->actor());
        } catch (LoanAlreadyReturnedException $e) {
            throw ValidationException::withMessages(['library_loan' => [$e->getMessage()]]);
        }

        return redirect('/app/library/circulation')->with('flash', 'Item checked in.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLoan(LibraryLoan $loan): array
    {
        return [
            'id' => $loan->id,
            'copyCode' => $loan->copy->code,
            'titleName' => $loan->copy->title->title,
            'studentName' => trim($loan->student->first_name.' '.$loan->student->last_name),
            'checkedOutAt' => $loan->checked_out_at->toDateString(),
            'dueAt' => $loan->due_at->toDateString(),
            'isOverdue' => $loan->isOverdue(),
        ];
    }
}
