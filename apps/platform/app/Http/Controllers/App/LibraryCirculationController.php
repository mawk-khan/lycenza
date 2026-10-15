<?php

namespace App\Http\Controllers\App;

use App\Domain\Library\Application\Exceptions\ConcurrentCheckoutConflictException;
use App\Domain\Library\Application\Exceptions\CopyNotAvailableException;
use App\Domain\Library\Application\Exceptions\DueDateBeforeCheckoutException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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

        // SWC.5 (ADR 0073 section 20.2): returned loans are listable too --
        // the same `status` filter `/api/v1` already offers under this same
        // capability -- so a returned loan (and, with `library.fines.view`, its
        // fine) stays findable after its check-in. Loans only: never a fine
        // list (owner decision X2).
        $validated = $request->validate(['status' => ['sometimes', Rule::in(['active', 'returned'])]]);
        $status = $validated['status'] ?? 'active';

        $loans = LibraryLoan::query()
            ->with(['copy.title', 'student'])
            ->where('status', $status)
            ->when($status === 'active', fn ($q) => $q->orderBy('due_at'), fn ($q) => $q->orderByDesc('checked_in_at'))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Library/Circulation/Index', [
            'loans' => $loans->through(fn (LibraryLoan $l) => $this->presentLoan($l, SchoolTimezone::resolve($school))),
            'status' => $status,
            'canManage' => $capabilities->canInSchool($context->actor(), 'library.circulation.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('library.circulation.manage', $school);

        // UAT-LIB-01: the earliest due date the form offers is the School's today.
        return Inertia::render('App/Library/Circulation/Create', [
            'today' => CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString(),
        ]);
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
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$term}%")
                ->orWhereHas('title', fn ($t) => $t->where('title', 'ilike', "%{$term}%"))))
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
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('student_number', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%")))
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
            $service->checkout($copy, $student, self::dueAt($validated['due_at'], $school), $context->actor());
        } catch (DueDateBeforeCheckoutException $e) {
            throw ValidationException::withMessages(['due_at' => [$e->getMessage()]]);
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

        // SWC.3 (ADR 0073 section 19.5): when check-in assessed a fine, show it in its loan's context.
        if (LibraryFine::query()->where('library_loan_id', $loan->id)->exists()) {
            return redirect()->route('app.library.circulation.show', $loan->id);
        }

        return redirect('/app/library/circulation')->with('flash', 'Item checked in.');
    }

    /**
     * UAT-C1 (UAT-LIB-01): the checkout form sends a calendar date, which
     * means "due back by the end of that day" in the School's timezone --
     * so a same-day loan is due tonight (never before its own checkout) and
     * a return on the due date is not overdue. A full timestamp (the API's
     * shape) is taken as given. Second precision: `due_at` stores whole
     * seconds, so 23:59:59.999999 would round into the next day.
     */
    private static function dueAt(string $input, School $school): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
            return Carbon::createFromFormat('Y-m-d', $input, SchoolTimezone::resolve($school))
                ->setTime(23, 59, 59)
                ->utc();
        }

        return Carbon::parse($input);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLoan(LibraryLoan $loan, DateTimeZone $timezone): array
    {
        return [
            'id' => $loan->id,
            'copyCode' => $loan->copy->code,
            'titleName' => $loan->copy->title->title,
            'studentName' => trim($loan->student->first_name.' '.$loan->student->last_name),
            // UAT-LIB-01: calendar dates in the School's timezone, the same
            // calendar the due date was chosen in.
            'checkedOutAt' => $loan->checked_out_at->copy()->setTimezone($timezone)->toDateString(),
            'dueAt' => $loan->due_at->copy()->setTimezone($timezone)->toDateString(),
            'isOverdue' => $loan->isOverdue(),
            'returnedAt' => $loan->checked_in_at?->copy()->setTimezone($timezone)->toDateString(),
        ];
    }
}
