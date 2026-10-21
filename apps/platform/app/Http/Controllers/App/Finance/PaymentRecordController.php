<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Payments\Application\Collections\DailyCollectionRow;
use App\Domain\Payments\Application\Collections\DailyCollectionsQuery;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentReceiptNotFoundException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentCorrectionReadService;
use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\ReceiptDocument;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ORG.7G-1 (ADR 0076 §50): Finance -> Payments received (the collections
 * list), one Payment and its printable receipt -- Campus-aware (C6) under
 * finance.payments.view through FinanceCampusAccess.
 *
 * A Campus reader sees a Payment (and its receipt, and its collections row)
 * only when every Charge it touches carries the SAME stored Campus, in scope
 * (G1-D4..G1-D6): a mixed-Campus or NULL-touching Payment is whole-School
 * only, and is the same 404 as an unknown one -- never a partial Payment.
 * Collection totals add up only the visible Payments, so none is counted
 * under two Campuses. The School-wide receipt series is unchanged.
 * Recording and correcting payments stay whole-School (ManualPaymentController,
 * PaymentController).
 */
class PaymentRecordController extends Controller
{
    use AuthorizesCampusScope;

    /**
     * LC-4 (M4): the collections list -- receipts RECORDED on a School-local
     * date (default: the School's today), with the Student, receipt number,
     * method, amount and current correction status, and totals across every
     * matching receipt. Request filters only narrow the reader's scope.
     */
    public function index(Request $request, TenantContext $context, FinanceCampusAccess $access, StudentIdentityLookup $identities, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.payments.view', $school);

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
        $studentIds = $search === '' ? null : $identities->idsMatching($school, $search);

        $result = $access->collections($school, $context->actor(), new DailyCollectionsQuery(
            from: $from, to: $to, receipt: $receipt, method: $validated['method'] ?? null,
            studentIds: $studentIds, page: (int) ($validated['page'] ?? 1),
        ));

        // Names for this page only: Students (name and number) and who recorded each receipt.
        $rows = $result->rows->getCollection();
        $students = $identities->labels($school, $rows->flatMap(fn (DailyCollectionRow $r) => $r->studentIds)->unique()->values()->all());
        $recorders = User::query()->whereIn('id', $rows->pluck('recordedByUserId')->filter()->unique()->values()->all())->pluck('name', 'id');

        return Inertia::render('App/Finance/Payments/Index', [
            'payments' => $result->rows
                ->through(fn (DailyCollectionRow $r) => [
                    'id' => $r->paymentId,
                    'receiptNumber' => $r->receiptNumber,
                    'students' => array_map(fn (string $id) => ($s = $students[$id] ?? null) === null
                        ? 'Student unavailable'
                        : "{$s->displayName} ({$s->studentNumber})", $r->studentIds),
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
            // ORG.7G-2: a Campus recorder records here too (one Campus per payment).
            'canRecord' => $access->recordsPayments($context->actor(), $school),
        ]);
    }

    public function show(Request $request, TenantContext $context, FinanceCampusAccess $access, PaymentCorrectionReadService $corrections, FreshMfaRequirement $mfa, string $payment): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.payments.view', $school);

        // A non-UUID id can never name a Payment (the read returns "not found").
        if (! Str::isUuid($payment)) {
            throw new NotFoundHttpException;
        }

        try {
            $detail = $access->payment($school, $payment, $context->actor());
        } catch (PaymentNotFoundException) {
            throw new NotFoundHttpException;
        }

        $outcome = $request->session()->get(ManualPaymentController::RECORDED_SESSION_KEY);
        $corrected = $request->session()->get(PaymentController::CORRECTED_SESSION_KEY);
        $presented = $this->presentDetail($detail, $school, $scope);

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
     * FEE.4 (ADR 0062 §17): the printable payment acknowledgement, titled
     * "Payment receipt" -- never a tax invoice, with no tax field (J:
     * DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED). Printing is
     * the browser's; nothing is stored or generated. ORG.7G-1: visible
     * exactly when its Payment is.
     */
    public function receipt(TenantContext $context, FinanceCampusAccess $access, StudentIdentityLookup $identities, string $payment): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.payments.view', $school);

        try {
            $document = $access->receipt($school, $payment, $context->actor());
        } catch (PaymentReceiptNotFoundException) {
            throw new NotFoundHttpException;
        }

        $students = $identities->labels($school, $document->studentIds);
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
                'students' => array_values(array_map(fn ($s) => [
                    'name' => $s->displayName,
                    'studentNumber' => $s->studentNumber,
                ], $students)),
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

    /**
     * Ledger internals (settlement account, journal entry) only for a
     * whole-School reader.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(PaymentDetail $payment, School $school, CampusScope $scope): array
    {
        $internals = $scope->isWholeSchool();

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
            'settlementLedgerAccountId' => $internals ? $payment->settlementLedgerAccountId : null,
            'journalEntryId' => $internals ? $payment->journalEntryId : null,
            'settledAt' => $payment->settledAt->toIso8601String(),
            'allocations' => $payment->allocations,
            'receiptNumber' => $payment->receiptNumber,
        ];
    }

    private function methodLabel(?string $method): ?string
    {
        return $method === null ? null : ManualPaymentMethod::tryFrom($method)?->label();
    }
}
