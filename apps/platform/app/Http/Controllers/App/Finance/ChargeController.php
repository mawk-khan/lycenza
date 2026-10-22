<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Application\AcademicYearChoices;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeAdministrationService;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\InvalidChargeAccountException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Finance\Application\Exceptions\FinanceException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
 *
 * ORG.7G-1 (ADR 0076 §50): the charge list and detail moved to the
 * Campus-aware ChargeHistoryController; this controller keeps the
 * whole-School finance.charges.manage actions (create, Student search,
 * assess, cancel).
 */
class ChargeController extends Controller
{
    use AuthorizesCapability;

    /** One-shot session key: the charges an ad-hoc charge resembled. */
    public const SIMILAR_SESSION_KEY = 'finance.similar_charges';

    public function create(Request $request, TenantContext $context, ChargeAdministrationService $service): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        // UAT-C4 (UAT-FIN-11): only the accounts an ad-hoc charge may use (the
        // fee-head rule: receivable = active asset, revenue = active income),
        // preselected only when there is exactly one; the current year first
        // and preselected (AcademicYearChoices).
        $choices = fn (string $type) => $service->accountChoices($school, $type, $context->actor())
            ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])
            ->values()->all();
        $receivable = $choices('asset');
        $revenue = $choices('income');
        $years = AcademicYear::query()->get();

        return Inertia::render('App/Finance/Charges/Create', [
            'receivableAccounts' => $receivable,
            'revenueAccounts' => $revenue,
            'defaultReceivableAccountId' => count($receivable) === 1 ? $receivable[0]['id'] : null,
            'defaultRevenueAccountId' => count($revenue) === 1 ? $revenue[0]['id'] : null,
            'academicYears' => AcademicYearChoices::options($years),
            'defaultAcademicYearId' => AcademicYearChoices::defaultId($years),
            // UAT-C4 (UAT-FIN-20): charges the last submission resembled (one-shot).
            'similarCharges' => $request->session()->get(self::SIMILAR_SESSION_KEY, []),
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

        $students = Student::query()
            ->tap(fn ($q) => StudentSearch::anyField($q, $validated['q']))
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
            'confirm_similar' => ['sometimes', 'boolean'],
        ]);

        $amount = $this->parseAmount($validated['amount'], $validated['currency']);
        $dueDate = isset($validated['due_date']) ? Carbon::parse($validated['due_date'])->toDateString() : null;
        // UAT-C4 (UAT-FIN-20): a warning, never a refusal -- the form shows the
        // similar charges and the user may assess anyway.
        if (! ($validated['confirm_similar'] ?? false) && Str::isUuid($validated['student_id']) && Str::isUuid($validated['academic_year_id'])) {
            $similar = $service->similarUncancelledCharges($school, $validated['student_id'], $validated['academic_year_id'], $validated['description'], $amount, $dueDate, $context->actor());
            if ($similar !== []) {
                return back()->withInput()->with(self::SIMILAR_SESSION_KEY, $similar)->withErrors([
                    'confirm_similar' => 'This Student already has a similar charge this year. Review it below, then assess anyway only if this is a separate charge.',
                ]);
            }
        }

        try {
            $result = $service->assess($school, new AssessChargeData(
                studentId: $validated['student_id'],
                academicYearId: $validated['academic_year_id'],
                description: $validated['description'],
                amount: $amount,
                receivableLedgerAccountId: $validated['receivable_ledger_account_id'],
                revenueLedgerAccountId: $validated['revenue_ledger_account_id'],
                campus: ChargeCampusAttribution::placementOnAssessmentDate(),
                dueDate: $validated['due_date'] ?? null,
            ), $context->actor());
        } catch (InvalidChargeAccountException $e) {
            throw ValidationException::withMessages([$e->field => [$e->getMessage()]]);
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

    public function cancel(Request $request, TenantContext $context, ChargeAdministrationService $service, string $charge): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);

        // ORG.8G (ADR 0076 §65): a listed reason (the note for `other`) and a fresh MFA code.
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(ChargeCancellationReason::CODES[ChargeCancellationReason::CANCEL])],
            'reason' => ['nullable', 'required_if:reason_code,other', 'string', 'max:255'],
        ]);
        $proof = app(FreshMfaRequirement::class)->requireForAction($request, $context->actor());

        try {
            $service->cancel($school, $charge, $context->actor(), $validated['reason_code'], $validated['reason'] ?? null, $proof);
        } catch (ChargeNotFoundException) {
            throw new NotFoundHttpException;
        } catch (ChargeHasPaymentAllocationsException) {
            // UAT-C3 (UAT-FIN-24): say what to do, not the raw charge id.
            return redirect("/app/finance/charges/{$charge}")->withErrors(['cancellation' => 'Payments still stand on this charge. Open each payment and move the money to the right charge (or reverse or refund it), then cancel this charge.']);
        } catch (FeesException $e) {
            // Already cancelled, payments allocated, fee-assessed (void the
            // assessment) or live concession adjustments (cancel them first).
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
}
