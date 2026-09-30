<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentReceiptNotFoundException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\PaymentQuery;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\PaymentReceiptReadService;
use App\Domain\Payments\Application\PaymentSummary;
use App\Domain\Payments\Application\ReceiptDocument;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
 * deliberate decision) and no mutation route of any kind exists here --
 * no settle/allocate/edit/delete/refund action. Phase 0O.11A: recording
 * an offline payment lives in `ManualPaymentController`
 * (`finance.payments.record`); this controller only shows the result,
 * with its provenance (provider-derived vs manually recorded). Refund
 * UI remains explicitly deferred (FINANCE.md 0G.7 scope).
 */
class PaymentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, PaymentReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.payments.view', $school);

        $validated = $request->validate([
            'provider_payment_reference' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = new PaymentQuery(
            providerPaymentReference: $validated['provider_payment_reference'] ?? null,
            page: (int) ($validated['page'] ?? 1),
        );

        $page = $service->listPayments($school, $query, $context->actor());

        return Inertia::render('App/Finance/Payments/Index', [
            'payments' => $page
                ->through(fn (PaymentSummary $p) => $this->presentSummary($p, $school))
                ->appends($request->only(['provider_payment_reference'])),
            'filters' => [
                'provider_payment_reference' => $validated['provider_payment_reference'] ?? '',
            ],
            'canRecord' => $capabilities->canInSchool($context->actor(), ManualPaymentRecordingService::CAPABILITY, $school),
        ]);
    }

    public function show(Request $request, TenantContext $context, PaymentReadService $service, string $payment): Response
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

        return Inertia::render('App/Finance/Payments/Show', [
            'payment' => $this->presentDetail($detail, $school),
            'recordedOutcome' => in_array($outcome, ['recorded', 'duplicate_replay'], true) ? $outcome : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(PaymentSummary $payment, School $school): array
    {
        return [
            'id' => $payment->paymentId,
            'source' => $payment->source,
            'provider' => $payment->provider,
            'method' => $payment->method,
            'methodLabel' => $this->methodLabel($payment->method),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'settledAt' => $payment->settledAt->toIso8601String(),
            'occurredOn' => $payment->settledAt->copy()->setTimezone($school->timezone)->toDateString(),
        ];
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
            ],
        ]);
    }

    private function methodLabel(?string $method): ?string
    {
        return $method === null ? null : ManualPaymentMethod::tryFrom($method)?->label();
    }
}
