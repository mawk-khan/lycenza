<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Payments\Application\Collections\DailyCollectionRow;
use App\Domain\Payments\Application\Collections\DailyCollectionsQuery;
use App\Domain\Payments\Application\Collections\DailyCollectionsReadService;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionIdempotencyConflictException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentReceiptNotFoundException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionReadService;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\PaymentReceiptReadService;
use App\Domain\Payments\Application\ReceiptDocument;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
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
 */
class PaymentController extends Controller
{
    use AuthorizesCapability;

    /** One-shot session key the Payment page reads after a correction. */
    public const CORRECTED_SESSION_KEY = 'finance.payment_correction_outcome';

    /**
     * LC-4 (M4): the collections list -- receipts RECORDED on a School-local
     * date (default: the School's today), with the Student, receipt number,
     * method, amount and current correction status, and totals across every
     * matching receipt. Read only under `finance.payments.view` (the Cashier
     * holds it); corrections stay on each Payment's own page.
     */
    public function index(Request $request, TenantContext $context, DailyCollectionsReadService $collections, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(DailyCollectionsReadService::CAPABILITY, $school);

        $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $methods = [...array_column(ManualPaymentRecordingService::methodOptions(), 'value'), 'provider'];
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'receipt' => ['sometimes', 'nullable', 'string', 'max:100'],
            'student' => ['sometimes', 'nullable', 'string', 'max:100'],
            'method' => ['sometimes', 'nullable', Rule::in($methods)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $from = $validated['from'] ?? $today;
        $to = $validated['to'] ?? $from;
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date, at most a year later.']);
        }
        $receipt = trim((string) ($validated['receipt'] ?? '')) ?: null;
        $search = trim((string) ($validated['student'] ?? ''));
        $studentIds = $search === '' ? null : Student::query()->tap(fn ($q) => StudentSearch::anyField($q, $search))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        $result = $collections->list($school, $context->actor(), new DailyCollectionsQuery(
            from: $from, to: $to, receipt: $receipt, method: $validated['method'] ?? null,
            studentIds: $studentIds, page: (int) ($validated['page'] ?? 1),
        ));

        // Names for this page only: Students (name and number) and who recorded each receipt.
        $rows = $result->rows->getCollection();
        $students = Student::query()->whereIn('id', $rows->flatMap(fn (DailyCollectionRow $r) => $r->studentIds)->unique()->values()->all())
            ->get(['id', 'school_id', 'first_name', 'middle_name', 'last_name', 'student_number'])->keyBy('id');
        $recorders = User::query()->whereIn('id', $rows->pluck('recordedByUserId')->filter()->unique()->values()->all())->pluck('name', 'id');

        return Inertia::render('App/Finance/Payments/Index', [
            'payments' => $result->rows
                ->through(fn (DailyCollectionRow $r) => [
                    'id' => $r->paymentId,
                    'receiptNumber' => $r->receiptNumber,
                    'students' => array_map(fn (string $id) => ($s = $students->get($id)) === null
                        ? 'Student unavailable'
                        : trim(collect([$s->first_name, $s->middle_name, $s->last_name])->filter()->implode(' '))." ({$s->student_number})", $r->studentIds),
                    'recordedAt' => $r->recordedAt->toIso8601String(),
                    'receivedOn' => $r->receivedOn,
                    'methodLabel' => $r->source === 'provider' ? 'Online ('.($r->provider ?? 'provider').')' : ($this->methodLabel($r->method) ?? 'Offline'),
                    'amount' => $r->amount,
                    'currency' => $r->currency,
                    'status' => $r->status,
                    'refunded' => $r->refunded,
                    'reallocated' => $r->reallocated,
                    'recordedBy' => $r->recordedByUserId === null ? null : ($recorders[$r->recordedByUserId] ?? null),
                ])
                ->withQueryString(),
            'totals' => $result->totals,
            'filters' => ['from' => $from, 'to' => $to, 'receipt' => $receipt ?? '', 'student' => $search, 'method' => $validated['method'] ?? ''],
            'today' => $today,
            'methods' => [...ManualPaymentRecordingService::methodOptions(), ['value' => 'provider', 'label' => 'Online (payment provider)']],
            'canRecord' => $capabilities->canInSchool($context->actor(), ManualPaymentRecordingService::CAPABILITY, $school),
        ]);
    }

    public function show(Request $request, TenantContext $context, PaymentReadService $service, PaymentCorrectionReadService $corrections, FreshMfaRequirement $mfa, string $payment): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.payments.view', $school);

        // A non-UUID id can never name a Payment -- querying the uuid
        // column with it would be a PostgreSQL error, not "not found".
        if (! Str::isUuid($payment)) {
            throw new NotFoundHttpException;
        }

        try {
            $detail = $service->getPaymentDetail($school, $payment, $context->actor());
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        }

        $outcome = $request->session()->get(ManualPaymentController::RECORDED_SESSION_KEY);
        $corrected = $request->session()->get(self::CORRECTED_SESSION_KEY);
        $presented = $this->presentDetail($detail, $school);

        return Inertia::render('App/Finance/Payments/Show', [
            'payment' => $presented,
            'recordedOutcome' => in_array($outcome, ['recorded', 'duplicate_replay'], true) ? $outcome : null,
            'corrections' => $corrections->forPayment($school, $detail->paymentId, $presented['occurredOn'], $context->actor()),
            'correctedOutcome' => in_array($corrected, ['recorded', 'duplicate_replay'], true) ? $corrected : null,
            // A fresh key per page load: a double-submitted form replays, a
            // second correction needs the page reloaded (rules 29-31).
            'correctionKey' => (string) Str::uuid(),
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
        ]);
    }

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

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(PaymentDetail $payment, School $school): array
    {
        return [
            'id' => $payment->paymentId,
            'source' => $payment->source,
            'provider' => $payment->provider,
            'providerPaymentReference' => $payment->providerPaymentReference,
            'method' => $payment->method,
            'methodLabel' => $this->methodLabel($payment->method),
            'manualReference' => $payment->manualReference,
            'recordedByName' => $payment->recordedByUserId !== null ? User::query()->find($payment->recordedByUserId)?->name : null,
            'occurredOn' => $payment->settledAt->copy()->setTimezone($school->timezone)->toDateString(),
            'recordedAt' => $payment->recordedAt->toIso8601String(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'settlementLedgerAccountId' => $payment->settlementLedgerAccountId,
            'journalEntryId' => $payment->journalEntryId,
            'settledAt' => $payment->settledAt->toIso8601String(),
            'allocations' => $payment->allocations,
            'receiptNumber' => $payment->receiptNumber,
        ];
    }

    /**
     * FEE.4 (ADR 0062 §17): the printable payment acknowledgement, titled
     * "Payment receipt" -- never a tax invoice, with no tax field (J:
     * DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED). Printing is
     * the browser's; nothing is stored or generated.
     */
    public function receipt(TenantContext $context, PaymentReceiptReadService $receipts, string $payment): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.payments.view', $school);

        try {
            $document = $receipts->forPayment($school, $payment, $context->actor());
        } catch (PaymentReceiptNotFoundException) {
            throw new NotFoundHttpException;
        }

        $students = Student::query()->whereIn('id', $document->studentIds)->get();
        $years = AcademicYear::query()->whereIn('id', collect($document->lines)->pluck('academicYearId')->filter()->unique()->all())->pluck('name', 'id');

        return Inertia::render('App/Finance/Payments/Receipt', [
            'receipt' => [
                'title' => ReceiptDocument::TITLE,
                'schoolName' => $school->name,
                'receiptNumber' => $document->receiptNumber,
                'seriesKey' => $document->seriesKey,
                'issuedOn' => $document->issuedAt->copy()->setTimezone($school->timezone)->toDateString(),
                'paymentId' => $document->paymentId,
                'receivedOn' => $document->settledAt->copy()->setTimezone($school->timezone)->toDateString(),
                'methodLabel' => $this->methodLabel($document->method) ?? ($document->source === 'provider' ? 'Online' : null),
                'reference' => $document->manualReference,
                'amount' => $document->amount,
                'currency' => $document->currency,
                'students' => $students->map(fn (Student $s) => [
                    'name' => collect([$s->first_name, $s->middle_name, $s->last_name])->filter()->implode(' '),
                    'studentNumber' => $s->student_number,
                ])->values()->all(),
                'lines' => collect($document->lines)->map(fn (array $l) => [
                    ...$l,
                    'academicYearName' => $l['academicYearId'] ? ($years[$l['academicYearId']] ?? null) : null,
                ])->all(),
                'corrections' => array_map(fn (array $c) => [
                    'kind' => $c['kind'],
                    'amount' => $c['amount'],
                    'on' => $c['recordedAt']->copy()->setTimezone($school->timezone)->toDateString(),
                ], $document->corrections),
            ],
        ]);
    }

    private function methodLabel(?string $method): ?string
    {
        return $method === null ? null : ManualPaymentMethod::tryFrom($method)?->label();
    }
}
