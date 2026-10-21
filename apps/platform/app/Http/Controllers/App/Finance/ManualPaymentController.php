<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\Exceptions\AllocationDoesNotSumToPaymentAmountException;
use App\Domain\Payments\Application\Exceptions\CampusPaymentUnavailableException;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException;
use App\Domain\Payments\Application\Exceptions\InvalidManualPaymentException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Application\Exceptions\ManualPaymentIdempotencyConflictException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\OutstandingCharge;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
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

/**
 * Phase 0O.11A (ADR 0031 implementation amendment, ADR 0057 section 3):
 * session-authenticated Inertia pages for recording an OFFLINE payment --
 * cash, bank transfer or cheque money the School already received outside
 * Lycenza. Lycenza moves no money here and collects no card or bank
 * credential. Thin: every read/write goes through
 * `ManualPaymentRecordingService`, which re-checks `finance.payments.record`
 * itself; the School is always the session TenantContext, never input.
 *
 * `Student` is read directly for display/search only -- the same
 * cross-module read-for-display pattern `ChargeController` uses.
 *
 * There is no edit/delete/void route: a recorded Payment is immutable (ADR
 * 0031 amendment section 2); corrections are new linked records on the
 * payment page (UAT-C3, `PaymentController::correct()`).
 */
class ManualPaymentController extends Controller
{
    use AuthorizesCampusScope;

    /** One-shot session key the Payment page reads after a recording. */
    public const RECORDED_SESSION_KEY = 'finance.manual_payment_outcome';

    public function create(Request $request, TenantContext $context, ManualPaymentRecordingService $service, StudentIdentityLookup $identities): Response
    {
        $school = $context->requireSchool();
        // ORG.7G-2 (ADR 0076 §51): Campus-aware (C6) -- a Campus recorder is
        // offered only Charges whose stored Campus it collects for.
        $scope = $this->authorizeCampusScope('finance.payments.record', $school);

        $validated = $request->validate([
            'student_id' => ['sometimes', 'uuid'],
            'charge_id' => ['sometimes', 'uuid'],
        ]);

        $charges = isset($validated['student_id']) ? $service->outstandingChargesForStudent($school, $validated['student_id'], $context->actor()) : [];
        // A Campus recorder learns nothing about a Student without collectable
        // (outstanding) debt in scope -- not even the name; the same rule as
        // the debtor search.
        $collectable = array_filter($charges, fn (OutstandingCharge $c) => Money::of($c->outstanding, $c->currency)->isPositive());
        $student = isset($validated['student_id']) && ($scope->isWholeSchool() || $collectable !== [])
            ? ($identities->labels($school, [$validated['student_id']])[$validated['student_id']] ?? null)
            : null;

        return Inertia::render('App/Finance/Payments/Record', [
            'idempotencyKey' => (string) Str::uuid(),
            'today' => Carbon::now($school->timezone)->toDateString(),
            'methods' => ManualPaymentRecordingService::methodOptions(),
            'settlementAccounts' => $service->settlementAccounts($school, $context->actor())
                ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])
                ->all(),
            'student' => $student === null ? null : [
                'id' => $student->id,
                'name' => $student->displayName,
                'studentNumber' => $student->studentNumber,
            ],
            'charges' => $student === null ? [] : array_map(
                fn (OutstandingCharge $c) => [
                    'id' => $c->chargeId,
                    'description' => $c->description,
                    'amount' => $c->amount,
                    'allocated' => $c->allocated,
                    'adjusted' => $c->adjusted,
                    'outstanding' => $c->outstanding,
                    'currency' => $c->currency,
                    'dueDate' => $c->dueDate?->toDateString(),
                    'campusId' => $c->campusId,
                ],
                $charges,
            ),
            // One payment pays charges of ONE campus for a Campus recorder (G2-D4/D5).
            'campusScoped' => ! $scope->isWholeSchool(),
            'campusNames' => $scope->isWholeSchool() ? [] : Campus::query()->whereIn('id', array_values(array_unique(array_filter(array_map(fn (OutstandingCharge $c) => $c->campusId, $charges)))))->pluck('name', 'id')->all(),
            'preselectedChargeId' => $validated['charge_id'] ?? null,
            'hasMfaFactor' => app(FreshMfaRequirement::class)->hasActiveFactor($context->actor()),
        ]);
    }

    /**
     * Same-School Student search for the recording form -- read-only JSON,
     * mirrors `ChargeController::searchStudents()`, gated by the recording
     * capability instead of `finance.charges.manage`.
     */
    public function searchStudents(Request $request, TenantContext $context, FinanceCampusAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('finance.payments.record', $school);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        // ORG.7G-2: a Campus recorder finds only debtors with open debt in
        // scope (leavers included), never a Student directory.
        return response()->json([
            'data' => array_map(fn (StudentLookupCandidate $s) => [
                'id' => $s->id,
                'studentNumber' => $s->studentNumber,
                'name' => $s->displayName,
            ], $access->collectionDebtors($school, $context->actor(), $validated['q'])),
        ]);
    }

    public function store(Request $request, TenantContext $context, ManualPaymentRecordingService $service, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('finance.payments.record', $school);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', 'string', Rule::enum(ManualPaymentMethod::class)],
            'amount' => ['required', 'string'],
            'occurred_on' => ['required', 'string', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:'.ManualPaymentRecordingService::REFERENCE_PATTERN],
            'settlement_ledger_account_id' => ['required', 'uuid'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.charge_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'string'],
        ]);

        $allocations = [];
        foreach ($validated['allocations'] as $index => $allocation) {
            // ORG.7G-2: canonical lowercase ids -- one lock order in PHP and
            // PostgreSQL alike (uuid validation accepts either case).
            $allocations[] = new ChargeAllocationInput(strtolower($allocation['charge_id']), $this->parseAmount($allocation['amount'], "allocations.{$index}.amount"));
        }

        // SR.4 (ADR 0071 §26.7): a posted Payment is immutable (corrected only
        // by new linked records, UAT-C3), so recording one needs a fresh code -- outside the
        // recording transaction.
        $mfa->requireForAction($request, $context->actor());

        try {
            $result = $service->record($school, new RecordManualPaymentData(
                method: ManualPaymentMethod::from($validated['method']),
                amount: $this->parseAmount($validated['amount'], 'amount'),
                occurredOn: $validated['occurred_on'],
                reference: $validated['reference'] ?? null,
                settlementLedgerAccountId: $validated['settlement_ledger_account_id'],
                allocations: $allocations,
                idempotencyKey: $validated['idempotency_key'],
            ), $context->actor());
        } catch (InvalidManualPaymentException $e) {
            throw ValidationException::withMessages([$e->field => [$e->getMessage()]]);
        } catch (AllocationDoesNotSumToPaymentAmountException) {
            throw ValidationException::withMessages(['amount' => ['The amounts applied to charges must add up exactly to the amount received.']]);
        } catch (ChargeNotFoundException) {
            throw ValidationException::withMessages(['allocations' => ['One of the selected charges was not found.']]);
        } catch (ChargeIsCancelledException) {
            throw ValidationException::withMessages(['allocations' => ['One of the selected charges has been cancelled.']]);
        } catch (ChargeAllocationExceedsChargeAmountException) {
            throw ValidationException::withMessages(['allocations' => ['An amount applied to a charge is more than that charge still has outstanding.']]);
        } catch (InvalidSettlementDataException $e) {
            throw ValidationException::withMessages(['allocations' => [$e->getMessage()]]);
        } catch (ManualPaymentIdempotencyConflictException $e) {
            throw ValidationException::withMessages(['idempotency_key' => [$e->getMessage()]]);
        } catch (CampusPaymentUnavailableException $e) {
            // ORG.7G-2: one generic answer -- never which charge or why.
            throw ValidationException::withMessages(['allocations' => [$e->getMessage()]]);
        }

        return redirect("/app/finance/payments/{$result->paymentId}")
            ->with(self::RECORDED_SESSION_KEY, $result->outcome->value);
    }

    /**
     * Mirrors `ChargeController::parseAmount()` -- rejects float-derived,
     * exponent or locale-formatted input before a `Money` is ever built.
     */
    private function parseAmount(mixed $amount, string $field): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                $field => ['The amount must be a positive decimal with at most 2 decimal places (e.g. "1000.00").'],
            ]);
        }

        try {
            return Money::of($amount, 'INR');
        } catch (InvalidMoneyException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }
}
