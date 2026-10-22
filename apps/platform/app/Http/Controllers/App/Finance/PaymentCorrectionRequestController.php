<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionIdempotencyConflictException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestStaleException;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionReadService;
use App\Domain\Payments\Application\PaymentCorrectionRequestService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ORG.8C (ADR 0076 §61): Finance -> Payment corrections. A reallocation,
 * reversal or recorded refund is REQUESTED here
 * (`finance.payment_corrections.request`), then approved or rejected by
 * someone else (`.approve`); an approval executes the correction at once.
 * Withdrawal is the requester's. Whole-School only. Every check is repeated
 * in `PaymentCorrectionRequestService`; a hidden button is never the only
 * protection. Approval re-verifies a fresh MFA code (rule 95) -- the step
 * that moves money; requesting and rejecting move none.
 */
class PaymentCorrectionRequestController extends Controller
{
    use AuthorizesCapability;

    /** One-shot session key the Payment page reads after a request action. */
    public const OUTCOME_SESSION_KEY = 'finance.payment_correction_outcome';

    public function index(Request $request, TenantContext $context, PaymentCorrectionReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PaymentCorrectionRequestService::VIEW, $school);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'rejected', 'withdrawn', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $validated['status'] ?? 'pending';

        return Inertia::render('App/Finance/PaymentCorrectionRequests/Index', [
            'requests' => $reads->requestQueue($school, $context->actor(), $status === 'all' ? null : $status, (int) ($validated['page'] ?? 1)),
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * Order: request capability -> input -> `PaymentCorrectionRequestService`,
     * which re-checks it and freezes the terms. Every refusal is a form error,
     * never a 500.
     */
    public function store(Request $request, TenantContext $context, PaymentCorrectionRequestService $service, string $payment): RedirectResponse
    {
        $school = $context->requireSchool();
        if (! Str::isUuid($payment)) {
            throw new NotFoundHttpException;
        }
        $this->authorizeCapability(PaymentCorrectionRequestService::REQUEST, $school);

        $kind = $request->input('kind');
        if (! in_array($kind, [PaymentCorrectionData::REALLOCATION, PaymentCorrectionData::REVERSAL, PaymentCorrectionData::REFUND], true)) {
            throw ValidationException::withMessages(['kind' => ['Choose reallocate, reverse or refund.']]);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            // How many corrections the Payment had on the page: a request never binds a state its requester did not see.
            'correction_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'charge_id' => [Rule::requiredIf($kind !== PaymentCorrectionData::REVERSAL), 'nullable', 'uuid'],
            'to_charge_id' => [Rule::requiredIf($kind === PaymentCorrectionData::REALLOCATION), 'nullable', 'uuid', 'different:charge_id'],
            'amount' => [Rule::requiredIf($kind !== PaymentCorrectionData::REVERSAL), 'nullable', 'string'],
            'account_id' => [Rule::requiredIf($kind === PaymentCorrectionData::REFUND), 'nullable', 'uuid'],
            'method' => [Rule::requiredIf($kind === PaymentCorrectionData::REFUND), 'nullable', 'string', Rule::enum(ManualPaymentMethod::class)],
            'refunded_on' => [Rule::requiredIf($kind === PaymentCorrectionData::REFUND), 'nullable', 'string', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:'.ManualPaymentRecordingService::REFERENCE_PATTERN],
            'confirm' => [Rule::requiredIf($kind === PaymentCorrectionData::REVERSAL), 'accepted_if:kind,reversal'],
        ], [
            'to_charge_id.different' => 'Choose a different charge to move the money to.',
            'confirm.required' => 'Confirm that this payment should be reversed.',
            'confirm.accepted_if' => 'Confirm that this payment should be reversed.',
        ]);

        try {
            $result = $service->request($school, new PaymentCorrectionData(
                paymentId: $payment,
                kind: $kind,
                reason: $validated['reason'],
                idempotencyKey: $validated['idempotency_key'],
                chargeId: $kind === PaymentCorrectionData::REVERSAL ? null : $validated['charge_id'],
                toChargeId: $kind === PaymentCorrectionData::REALLOCATION ? $validated['to_charge_id'] : null,
                amount: $kind === PaymentCorrectionData::REVERSAL ? null : $this->parseAmount($validated['amount']),
                accountId: $kind === PaymentCorrectionData::REFUND ? $validated['account_id'] : null,
                method: $kind === PaymentCorrectionData::REFUND ? ManualPaymentMethod::from($validated['method']) : null,
                refundedOn: $kind === PaymentCorrectionData::REFUND ? $validated['refunded_on'] : null,
                reference: $kind === PaymentCorrectionData::REFUND ? ($validated['reference'] ?? null) : null,
            ), $context->actor(), (int) $validated['correction_count']);
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        } catch (PaymentCorrectionRefusedException $e) {
            throw ValidationException::withMessages([$this->fieldFor($e->errorCode()) => [$e->getMessage()]]);
        } catch (PaymentCorrectionRequestStaleException $e) {
            throw ValidationException::withMessages(['kind' => [$e->getMessage()]]);
        } catch (PaymentCorrectionRequestRefusedException $e) {
            throw ValidationException::withMessages([$e->errorCode() === 'PAYMENT_CORRECTION_REQUEST_REFERENCE_INVALID' ? 'charge_id' : 'idempotency_key' => [$e->getMessage()]]);
        }

        return redirect("/app/finance/payments/{$payment}")
            ->with(self::OUTCOME_SESSION_KEY, $result['replayed'] ? 'duplicate_replay' : 'requested');
    }

    /** Approval executes the correction -- a fresh code first (after the capability, outside the transaction). */
    public function approve(Request $request, TenantContext $context, PaymentCorrectionRequestService $service, string $correctionRequest): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PaymentCorrectionRequestService::APPROVE, $school);
        $mfa = app(FreshMfaRequirement::class)->requireForAction($request, $context->actor());

        return $this->decided(fn () => $service->approve($school, $correctionRequest, $context->actor(), $mfa)->payment_id, 'approved');
    }

    public function reject(TenantContext $context, PaymentCorrectionRequestService $service, string $correctionRequest): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PaymentCorrectionRequestService::APPROVE, $school);

        return $this->decided(fn () => $service->reject($school, $correctionRequest, $context->actor())->payment_id, 'rejected');
    }

    public function withdraw(TenantContext $context, PaymentCorrectionRequestService $service, string $correctionRequest): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(PaymentCorrectionRequestService::REQUEST, $school);

        return $this->decided(fn () => $service->withdraw($school, $correctionRequest, $context->actor())->payment_id, 'withdrawn');
    }

    /**
     * Back to the request's Payment page; a refusal is a form error there.
     * A request that does not exist (or another School's) is an unknown 404.
     *
     * @param  callable(): string  $operation  the decision; returns the request's Payment id
     */
    private function decided(callable $operation, string $outcome): RedirectResponse
    {
        try {
            $paymentId = $operation();
        } catch (PaymentCorrectionRequestRefusedException $e) {
            if ($e->errorCode() === 'PAYMENT_CORRECTION_REQUEST_NOT_FOUND') {
                throw new NotFoundHttpException;
            }

            return back()->withErrors(['correction_request' => $e->getMessage()]);
        } catch (PaymentCorrectionRequestStaleException|PaymentCorrectionRefusedException|PaymentCorrectionIdempotencyConflictException $e) {
            return back()->withErrors(['correction_request' => $e->getMessage()]);
        } catch (ChargeNotFoundException) {
            return back()->withErrors(['correction_request' => 'One of the requested charges was not found.']);
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        }

        return redirect("/app/finance/payments/{$paymentId}")->with(self::OUTCOME_SESSION_KEY, $outcome);
    }

    private function fieldFor(string $code): string
    {
        return match ($code) {
            'PAYMENT_CORRECTION_REASON_REQUIRED' => 'reason',
            'PAYMENT_CORRECTION_AMOUNT_INVALID', 'PAYMENT_CORRECTION_EXCEEDS_PAYMENT', 'PAYMENT_CORRECTION_DESTINATION_FULL' => 'amount',
            'PAYMENT_CORRECTION_DESTINATION_INVALID' => 'to_charge_id',
            'PAYMENT_CORRECTION_ACCOUNT_INVALID' => 'account_id',
            'PAYMENT_CORRECTION_DATE_INVALID' => 'refunded_on',
            'PAYMENT_CORRECTION_METHOD_INVALID' => 'method',
            default => 'kind',
        };
    }

    /** Same exact-decimal rule as recording a payment -- no float, no exponent. */
    private function parseAmount(mixed $amount): Money
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['The amount must be a positive decimal with at most 2 decimal places (e.g. "1000.00").']]);
        }

        try {
            return Money::of($amount, 'INR');
        } catch (InvalidMoneyException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }
    }
}
