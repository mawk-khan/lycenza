<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionIdempotencyConflictException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\School;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 0G.7: session-authenticated, READ-ONLY Inertia pages for
 * Payments. Follows the same convention every other App/Finance
 * controller in this namespace follows (NOT the Bearer-token JSON API
 * under /api/v1 that 0G.6 built). Delegates every read to
 * `PaymentReadService` -- the exact same Application-layer service the
 * JSON API controller calls -- never a raw `Payment`/`PaymentAllocation`
 * Eloquent query.
 *
 * There is no `finance.payments.manage` capability (0G.5's own
 * deliberate decision) and no edit or delete action. Phase 0O.11A:
 * recording an offline payment lives in `ManualPaymentController`
 * (`finance.payments.record`). UAT-C3 (ADR 0031 amendment §3, ADR 0075
 * §16): `correct()` is the one correction write -- reallocate, reverse or
 * refund through `PaymentCorrectionService`, which records a new linked
 * correction and never changes the posted Payment.
 *
 * ORG.7G-1 (ADR 0076 §50): the collections list, Payment page and receipt
 * moved to the Campus-aware PaymentRecordController; corrections stay
 * here, whole-School (their capabilities are checked with the ordinary
 * whole-School Gate, so a Campus grant never reaches them).
 */
class PaymentController extends Controller
{
    use AuthorizesCapability;

    /** One-shot session key the Payment page reads after a correction. */
    public const CORRECTED_SESSION_KEY = 'finance.payment_correction_outcome';

    /**
     * UAT-C3: reallocate, reverse or refund a posted Payment. Order: the
     * kind's capabilities -> input -> fresh MFA (outside the transaction,
     * rule 95) -> `PaymentCorrectionService`, which re-checks the
     * capabilities and decides under its locks. Every refusal is a form
     * error, never a 500.
     */
    public function correct(Request $request, TenantContext $context, PaymentCorrectionService $service, FreshMfaRequirement $mfa, string $payment): RedirectResponse
    {
        $school = $context->requireSchool();
        if (! Str::isUuid($payment)) {
            throw new NotFoundHttpException;
        }
        $kind = $request->input('kind');
        if (! in_array($kind, [PaymentCorrectionData::REALLOCATION, PaymentCorrectionData::REVERSAL, PaymentCorrectionData::REFUND], true)) {
            throw ValidationException::withMessages(['kind' => ['Choose reallocate, reverse or refund.']]);
        }
        foreach (PaymentCorrectionService::capabilitiesFor($kind) as $capability) {
            $this->authorizeCapability($capability, $school);
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
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

        $mfa->requireForAction($request, $context->actor());

        try {
            $result = $service->correct($school, new PaymentCorrectionData(
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
            ), $context->actor());
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        } catch (PaymentCorrectionRefusedException $e) {
            throw ValidationException::withMessages([$this->fieldFor($e->errorCode()) => [$e->getMessage()]]);
        } catch (ChargeNotFoundException) {
            throw ValidationException::withMessages(['charge_id' => ['One of the selected charges was not found.']]);
        } catch (PaymentCorrectionIdempotencyConflictException $e) {
            throw ValidationException::withMessages(['idempotency_key' => [$e->getMessage()]]);
        }

        return redirect("/app/finance/payments/{$payment}")
            ->with(self::CORRECTED_SESSION_KEY, $result['replayed'] ? 'duplicate_replay' : 'recorded');
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
