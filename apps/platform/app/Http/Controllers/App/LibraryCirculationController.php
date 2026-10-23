<?php

namespace App\Http\Controllers\App;

use App\Domain\Library\Application\Exceptions\ConcurrentCheckoutConflictException;
use App\Domain\Library\Application\Exceptions\CopyNotAvailableException;
use App\Domain\Library\Application\Exceptions\DueDateBeforeCheckoutException;
use App\Domain\Library\Application\Exceptions\LibraryCheckoutUnavailableException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Library\Application\LibraryCirculationAccess;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
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
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, LibraryCirculationAccess $access): Response
    {
        $school = $context->requireSchool();
        // ORG.7D-5 (ADR 0076 §41): loans by their frozen Campus, in SQL.
        $scope = $this->authorizeCampusScope('library.circulation.view', $school);

        // SWC.5 (ADR 0073 section 20.2): returned loans are listable too --
        // the same `status` filter `/api/v1` already offers under this same
        // capability -- so a returned loan (and, with `library.fines.view`, its
        // fine) stays findable after its check-in. Loans only: never a fine
        // list (owner decision X2).
        // ORG.9C-1 (ADR 0076 §69), PV1-07 read completion (LIB-031/035/037):
        // `all`, a School-local date range (issued -- or returned, on the
        // returned tab), a Student name/number and a Campus. Each only narrows
        // the scope-restricted query in SQL; still never a fine list.
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'returned', 'all'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'student' => ['sometimes', 'nullable', 'string', 'max:100'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $status = $validated['status'] ?? 'active';
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $student = trim((string) ($validated['student'] ?? ''));
        $campusId = $validated['campus_id'] ?? null;
        $dated = $status === 'returned' ? 'library_loans.checked_in_at' : 'library_loans.checked_out_at';

        $loans = $access->loans($scope)
            ->with(['copy.title', 'student'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($from !== null, fn ($q) => $q->where($dated, '>=', SchoolTimezone::startOfDayUtc($school, (string) $from)))
            ->when($to !== null, fn ($q) => $q->where($dated, '<', SchoolTimezone::startOfNextDayUtc($school, (string) $to)))
            ->when($campusId !== null, fn ($q) => $q->where('library_loans.campus_id', $campusId))
            ->when($student !== '', function ($q) use ($student) {
                $needle = addcslashes($student, '%_\\');
                $q->whereHas('student', fn ($s) => $s->where(fn ($w) => $w->where('student_number', 'ilike', $needle.'%')
                    ->orWhere('first_name', 'ilike', '%'.$needle.'%')
                    ->orWhere('last_name', 'ilike', '%'.$needle.'%')));
            })
            ->when($status === 'active', fn ($q) => $q->orderBy('due_at'))
            ->when($status === 'returned', fn ($q) => $q->orderByDesc('checked_in_at'))
            ->when($status === 'all', fn ($q) => $q->orderByDesc('checked_out_at'))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Library/Circulation/Index', [
            'loans' => $loans->through(fn (LibraryLoan $l) => $this->presentLoan($l, SchoolTimezone::resolve($school))),
            'status' => $status,
            'filters' => ['from' => $from ?? '', 'to' => $to ?? '', 'student' => $student, 'campus_id' => $campusId ?? ''],
            // A filter, never authority: only the Campuses the reader's scope covers are offered.
            'campuses' => Campus::query()->where('status', 'active')->tap(fn ($q) => $scope->restrict($q, 'campuses.id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'canManage' => ! $scopes->campusScope($context->actor(), 'library.circulation.manage', $school)->isNone(),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('library.circulation.manage', $school);

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
    public function searchAvailableCopies(Request $request, TenantContext $context, LibraryCirculationAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        // ORG.7D-5: a Campus-scoped librarian lends only their Campuses' Copies
        // (never a School-shared, NULL-Campus one).
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $term = (string) $request->query('q', '');
        $like = '%'.addcslashes($term, '%_\\').'%';

        $copies = $access->copies($scope)
            ->with('title')
            ->where('status', 'active')
            ->whereDoesntHave('activeLoan')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', $like)
                ->orWhereHas('title', fn ($t) => $t->where('title', 'ilike', $like))))
            ->limit(10)
            ->get();

        return response()->json(['data' => $copies->map(fn (LibraryCopy $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'title' => $c->title->title,
        ])->all()]);
    }

    public function searchStudents(Request $request, TenantContext $context, LibraryCirculationAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        // ORG.7D-5: the borrower picker (StudentPurposeLookup for a Campus-scoped
        // librarian) -- a candidate, never write authority.
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $term = (string) $request->query('q', '');

        return response()->json(['data' => array_map(fn (StudentLookupCandidate $c) => [
            'id' => $c->id,
            'studentNumber' => $c->studentNumber,
            'name' => $c->displayName,
        ], $access->borrowerCandidates($school, $scope, $term))]);
    }

    public function store(Request $request, TenantContext $context, LibraryLoanService $service, LibraryCirculationAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $validated = $request->validate([
            'library_copy_id' => ['required', 'string'],
            'student_id' => ['required', 'string'],
            'due_at' => ['required', 'date'],
        ]);

        $copy = $access->copy($scope, $validated['library_copy_id']);

        try {
            $student = $access->borrower($scope, $validated['student_id']);
            $service->checkout($copy, $student, self::dueAt($validated['due_at'], $school), $context->actor(), $access->writeGuard($school, $scope));
        } catch (LibraryCheckoutUnavailableException $e) {
            throw ValidationException::withMessages(['library_copy_id' => [$e->getMessage()]]);
        } catch (DueDateBeforeCheckoutException $e) {
            throw ValidationException::withMessages(['due_at' => [$e->getMessage()]]);
        } catch (StudentNotEligibleException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (CopyNotAvailableException|ConcurrentCheckoutConflictException $e) {
            throw ValidationException::withMessages(['library_copy_id' => [$e->getMessage()]]);
        }

        return redirect('/app/library/circulation')->with('flash', 'Item checked out.');
    }

    public function checkIn(TenantContext $context, CapabilityResolver $capabilities, LibraryLoanService $service, LibraryCirculationAccess $access, string $libraryLoan): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7D-5: by the loan's frozen Campus (no cross-Campus return).
        $scope = $this->authorizeCampusScope('library.circulation.manage', $school);

        $loan = $access->loan($scope, $libraryLoan);

        try {
            $service->checkIn($loan, $context->actor(), $access->writeGuard($school, $scope));
        } catch (LoanAlreadyReturnedException $e) {
            throw ValidationException::withMessages(['library_loan' => [$e->getMessage()]]);
        }

        // SWC.3 (ADR 0073 section 19.5): when check-in assessed a fine, show it in its loan's context.
        // ORG.7D-5: only a fines viewer learns that a fine was assessed.
        if ($capabilities->canInSchool($context->actor(), 'library.fines.view', $school) && LibraryFine::query()->where('library_loan_id', $loan->id)->exists()) {
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
