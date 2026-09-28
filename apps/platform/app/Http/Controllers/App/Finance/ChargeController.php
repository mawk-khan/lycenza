<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeDetail;
use App\Domain\Fees\Application\ChargeQuery;
use App\Domain\Fees\Application\ChargeReadService;
use App\Domain\Fees\Application\ChargeSummary;
use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Finance\Application\Exceptions\FinanceException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 0G.7: session-authenticated Inertia pages for Charges --
 * list/detail, assessment, cancellation. Follows the same convention
 * every other App/Finance controller in this namespace follows (NOT
 * the Bearer-token JSON API under /api/v1 that 0G.6 built). Delegates
 * every read/write to `ChargeReadService`/`ChargeAdministrationService`
 * -- the exact same Application-layer services the JSON API controller
 * calls -- never `ChargeService` directly and never a raw `Charge`
 * Eloquent query.
 *
 * `Student`/`AcademicYear` are read directly here (read-only, display
 * purposes only) -- the same established cross-module read-for-display
 * pattern `App\Domain\Communications\Http\Controllers\CommunicationAudienceSearchController`
 * already uses for its own audience-picker searches; `ChargeSummary`/
 * `ChargeDetail` deliberately carry only `studentId`/`academicYearId`
 * (FINANCE.md's own disclosure-boundary discipline), so resolving a
 * display name is this transport layer's job, not the Application
 * layer's.
 *
 * No edit/update route exists -- a recognized Charge's amount/Student/
 * AcademicYear/account mapping remain structurally immutable (0G.4);
 * the only post-assessment transition is `cancel()`.
 */
class ChargeController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, ChargeReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);

        $validated = $request->validate([
            'student_id' => ['sometimes', 'uuid'],
            'academic_year_id' => ['sometimes', 'uuid'],
            'include_cancelled' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new ChargeQuery(
            studentId: $validated['student_id'] ?? null,
            academicYearId: $validated['academic_year_id'] ?? null,
            includeCancelled: (bool) ($validated['include_cancelled'] ?? false),
            page: (int) ($validated['page'] ?? 1),
        );

        $page = $service->listCharges($school, $query, $context->actor());
        $students = $this->studentNamesFor($page->getCollection()->pluck('studentId')->all());

        return Inertia::render('App/Finance/Charges/Index', [
            'charges' => $page
                ->through(fn (ChargeSummary $c) => $this->presentSummary($c, $students[$c->studentId] ?? null))
                ->appends($request->only(['student_id', 'academic_year_id', 'include_cancelled'])),
            'filters' => [
                'student_id' => $validated['student_id'] ?? '',
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'include_cancelled' => (bool) ($validated['include_cancelled'] ?? false),
            ],
            'selectedStudentName' => isset($validated['student_id'])
                ? $this->studentNamesFor([$validated['student_id']])[$validated['student_id']] ?? null
                : null,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code'])
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])
                ->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'finance.charges.manage', $school),
        ]);
    }

    public function create(TenantContext $context, LedgerReadService $ledger): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        $accounts = $ledger->listAccounts($school, $context->actor());

        return Inertia::render('App/Finance/Charges/Create', [
            'ledgerAccounts' => $accounts
                ->filter(fn (LedgerAccountSummary $a) => $a->status === 'active')
                ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])
                ->values()
                ->all(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code', 'status'])
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code, 'status' => $y->status])
                ->all(),
        ]);
    }

    /**
     * Same-School Student name search for the assessment form's Student
     * picker -- a plain JSON endpoint (not an Inertia page), read-only,
     * mirrors `App\Http\Controllers\App\StudentGuardianRelationshipController::searchGuardians()`
     * exactly.
     */
    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $term = '%'.$validated['q'].'%';
        $students = Student::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term)
                ->orWhere('student_number', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'studentNumber' => $s->student_number,
                'firstName' => $s->first_name,
                'middleName' => $s->middle_name,
                'lastName' => $s->last_name,
            ])->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, ChargeAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $result = $service->assess($school, new AssessChargeData(
                studentId: $validated['student_id'],
                academicYearId: $validated['academic_year_id'],
                description: $validated['description'],
                amount: $this->parseAmount($validated['amount'], $validated['currency']),
                receivableLedgerAccountId: $validated['receivable_ledger_account_id'],
                revenueLedgerAccountId: $validated['revenue_ledger_account_id'],
                dueDate: $validated['due_date'] ?? null,
            ), $context->actor());
        } catch (StudentNotFoundException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (AcademicYearNotFoundException $e) {
            throw ValidationException::withMessages(['academic_year_id' => [$e->getMessage()]]);
        } catch (LedgerAccountNotFoundException $e) {
            throw ValidationException::withMessages(['receivable_ledger_account_id' => [$e->getMessage()]]);
        } catch (FeesException|FinanceException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        return redirect("/app/finance/charges/{$result->chargeId}");
    }

    public function show(TenantContext $context, ChargeReadService $service, CapabilityResolver $capabilities, string $charge): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);

        try {
            $detail = $service->getChargeDetail($school, $charge, $context->actor());
        } catch (ChargeNotFoundException) {
            throw new NotFoundHttpException;
        }

        $student = Student::query()->find($detail->studentId);
        $academicYear = AcademicYear::query()->find($detail->academicYearId);

        return Inertia::render('App/Finance/Charges/Show', [
            'charge' => [
                ...$this->presentDetail($detail),
                'studentName' => $student ? $this->fullName($student) : null,
                'studentNumber' => $student?->student_number,
                'academicYearName' => $academicYear?->name,
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'finance.charges.manage', $school),
            // Phase 0O.11A: a link to record an offline payment for this
            // Charge's Student; the recording route re-checks everything.
            'canRecordPayment' => $capabilities->canInSchool($context->actor(), 'finance.payments.record', $school),
        ]);
    }

    public function cancel(Request $request, TenantContext $context, ChargeAdministrationService $service, string $charge): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $service->cancel($school, $charge, $context->actor(), $validated['reason'] ?? null);
        } catch (ChargeNotFoundException) {
            throw new NotFoundHttpException;
        } catch (ChargeAlreadyCancelledException|ChargeHasPaymentAllocationsException $e) {
            return redirect("/app/finance/charges/{$charge}")->withErrors(['cancellation' => $e->getMessage()]);
        }

        return redirect("/app/finance/charges/{$charge}");
    }

    private function parseAmount(mixed $amount, string $currency): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => ['The amount must be a positive decimal string with at most 2 decimal places (e.g. "1000.00").'],
            ]);
        }

        try {
            return Money::of($amount, $currency);
        } catch (InvalidMoneyException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }
    }

    private function fullName(Student $student): string
    {
        return collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    }

    /**
     * @param  list<string>  $studentIds
     * @return array<string, string>
     */
    private function studentNamesFor(array $studentIds): array
    {
        return Student::query()->whereIn('id', array_unique($studentIds))->get()
            ->mapWithKeys(fn (Student $s) => [$s->id => $this->fullName($s)])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(ChargeSummary $charge, ?string $studentName): array
    {
        return [
            'id' => $charge->chargeId,
            'studentId' => $charge->studentId,
            'studentName' => $studentName,
            'academicYearId' => $charge->academicYearId,
            'description' => $charge->description,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'dueDate' => $charge->dueDate?->toDateString(),
            'cancelledAt' => $charge->cancelledAt?->toIso8601String(),
            'createdAt' => $charge->createdAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(ChargeDetail $charge): array
    {
        return [
            'id' => $charge->chargeId,
            'studentId' => $charge->studentId,
            'academicYearId' => $charge->academicYearId,
            'description' => $charge->description,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'dueDate' => $charge->dueDate?->toDateString(),
            'receivableLedgerAccountId' => $charge->receivableLedgerAccountId,
            'revenueLedgerAccountId' => $charge->revenueLedgerAccountId,
            'journalEntryId' => $charge->journalEntryId,
            'cancelledAt' => $charge->cancelledAt?->toIso8601String(),
            'cancellationJournalEntryId' => $charge->cancellationJournalEntryId,
            'createdAt' => $charge->createdAt->toIso8601String(),
        ];
    }
}
