<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\Exceptions\AllocationDoesNotSumToPaymentAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException;
use App\Domain\Payments\Application\Exceptions\InvalidManualPaymentException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Application\Exceptions\ManualPaymentIdempotencyConflictException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\OutstandingCharge;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
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
 * There is still no edit/delete/refund/void/reversal route: a recorded
 * Payment is immutable (ADR 0031 amendment section 2).
 */
class ManualPaymentController extends Controller
{
    use AuthorizesCapability;

    /** One-shot session key the Payment page reads after a recording. */
    public const RECORDED_SESSION_KEY = 'finance.manual_payment_outcome';

    public function create(Request $request, TenantContext $context, ManualPaymentRecordingService $service): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(ManualPaymentRecordingService::CAPABILITY, $school);

        $validated = $request->validate([
            'student_id' => ['sometimes', 'uuid'],
            'charge_id' => ['sometimes', 'uuid'],
        ]);

        $student = isset($validated['student_id']) ? Student::query()->find($validated['student_id']) : null;

        return Inertia::render('App/Finance/Payments/Record', [
            'idempotencyKey' => (string) Str::uuid(),
            'today' => Carbon::now($school->timezone)->toDateString(),
            'methods' => ManualPaymentRecordingService::methodOptions(),
            'settlementAccounts' => $service->settlementAccounts($school, $context->actor())
                ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])
                ->all(),
            'student' => $student === null ? null : [
                'id' => $student->id,
                'name' => $this->fullName($student),
                'studentNumber' => $student->student_number,
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
                ],
                $service->outstandingChargesForStudent($school, $student->id, $context->actor()),
            ),
            'preselectedChargeId' => $validated['charge_id'] ?? null,
        ]);
    }

    /**
     * Same-School Student search for the recording form -- read-only JSON,
     * mirrors `ChargeController::searchStudents()`, gated by the recording
     * capability instead of `finance.charges.manage`.
     */
    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(ManualPaymentRecordingService::CAPABILITY, $school);

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
                'name' => $this->fullName($s),
            ])->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, ManualPaymentRecordingService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(ManualPaymentRecordingService::CAPABILITY, $school);

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
            $allocations[] = new ChargeAllocationInput($allocation['charge_id'], $this->parseAmount($allocation['amount'], "allocations.{$index}.amount"));
        }

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

    private function fullName(Student $student): string
    {
        return collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    }
}
