<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\ChargeDetail;
use App\Domain\Fees\Application\ChargeQuery;
use App\Domain\Fees\Application\ChargeSummary;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\FeeConcessionReadService;
use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Payments\Application\LateFeeReadService;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ORG.7G-1 (ADR 0076 §50): Finance -> Charges, the charge history list and
 * one Charge -- Campus-aware (C6) under finance.charges.view through
 * FinanceCampusAccess. A Campus reader sees only Charges whose STORED Campus
 * (charges.campus_id) its scope covers -- never a NULL-Campus (whole-School)
 * Charge, never by the Student's current Campus -- and an out-of-scope
 * Charge is the same 404 as an unknown one.
 *
 * On one Charge, a reader with finance.payments.view sees the Payments it may
 * open (PaymentCampusVisibility) and, separately, the amount of any other
 * Payment on it (G1-D7: the Charge's balance stays correct; the hidden
 * Payment's id and receipt never appear). Ledger internals (accounts,
 * journal entries) and the full statement link are whole-School only.
 * Creating and cancelling charges stay on ChargeController (whole-School,
 * finance.charges.manage).
 */
class ChargeHistoryController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, FinanceCampusAccess $access, StudentIdentityLookup $identities, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.charges.view', $school);

        $validated = $request->validate([
            'student_id' => ['sometimes', 'uuid'],
            'academic_year_id' => ['sometimes', 'uuid'],
            'include_cancelled' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $access->charges($school, new ChargeQuery(
            studentId: $validated['student_id'] ?? null,
            academicYearId: $validated['academic_year_id'] ?? null,
            includeCancelled: (bool) ($validated['include_cancelled'] ?? false),
            page: (int) ($validated['page'] ?? 1),
        ), $context->actor());
        $students = $identities->labels($school, $page->getCollection()->pluck('studentId')->all());

        return Inertia::render('App/Finance/Charges/Index', [
            'charges' => $page
                ->through(fn (ChargeSummary $c) => $this->presentSummary($c, $students[$c->studentId]->displayName ?? null))
                ->appends($request->only(['student_id', 'academic_year_id', 'include_cancelled'])),
            'filters' => [
                'student_id' => $validated['student_id'] ?? '',
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'include_cancelled' => (bool) ($validated['include_cancelled'] ?? false),
            ],
            // A name only for a Student the reader's own rows already show.
            'selectedStudentName' => isset($validated['student_id']) && isset($students[$validated['student_id']])
                ? $students[$validated['student_id']]->displayName
                : null,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code'])
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])
                ->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'finance.charges.manage', $school),
        ]);
    }

    public function show(TenantContext $context, FinanceCampusAccess $access, StudentIdentityLookup $identities, CapabilityResolver $capabilities, FeeConcessionReadService $concessions, LateFeeReadService $lateFees, string $charge): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $scope = $this->authorizeCampusScope('finance.charges.view', $school);

        try {
            $detail = $access->charge($school, $charge, $actor);
        } catch (ChargeNotFoundException) {
            throw new NotFoundHttpException;
        }

        $student = $identities->labels($school, [$detail->studentId])[$detail->studentId] ?? null;
        $academicYear = AcademicYear::query()->find($detail->academicYearId);
        // finance.payments.view is optional here: without it no Payment is listed.
        $payments = $access->chargePayments($school, $actor, $detail->chargeId);
        $links = $lateFees->linksForCharge($school, $detail->chargeId, $actor);

        return Inertia::render('App/Finance/Charges/Show', [
            'charge' => [
                ...$this->presentDetail($detail, $scope),
                'studentName' => $student?->displayName,
                'studentNumber' => $student?->studentNumber,
                'academicYearName' => $academicYear?->name,
            ],
            'canManage' => $capabilities->canInSchool($actor, 'finance.charges.manage', $school),
            // ORG.8G (ADR 0076 §65): the listed cancel reasons and whether a fresh code can be given.
            'cancelReasons' => ChargeCancellationReason::CODES[ChargeCancellationReason::CANCEL],
            'hasMfaFactor' => app(FreshMfaRequirement::class)->hasActiveFactor($actor),
            // Phase 0O.11A: a link to record an offline payment for this
            // Charge's Student; the recording route re-checks everything.
            // ORG.7G-2: also a Campus recorder, for a Charge of a Campus it collects for.
            'canRecordPayment' => $access->canCollect($actor, $school, $detail->campusId),
            // FEE.3: the charge's posted concession adjustments (Highly
            // Sensitive -- only with finance.fee_concessions.view, audited
            // by the read service) and a link to request a concession.
            'adjustments' => $capabilities->canInSchool($actor, FeeConcessionReadService::VIEW, $school)
                ? $concessions->listAdjustments($school, ['charge_id' => $detail->chargeId], $actor)
                    ->map(fn (FeeAdjustment $a) => FeeSetupPresenter::adjustment($a))->values()->all()
                : null,
            'canRequestConcession' => $capabilities->canInSchool($actor, FeeConcessionService::REQUEST, $school),
            // FEE.5: late fees raised on this charge, or its source if it is one
            // (a linked charge of the same stored Campus).
            'lateFees' => [
                'raised' => $links['lateFees']->map(fn ($a) => ['assessmentId' => $a->id, 'chargeId' => $a->charge_id, 'voided' => $a->voided_at !== null])->values()->all(),
                'sourceChargeId' => $links['sourceOf']?->source_charge_id,
            ],
            // FEE.4: the full Student fee statement is whole-School only.
            'canViewStatement' => $access->holdsWholeSchool($actor, 'finance.charges.view', $school) && $access->holdsWholeSchool($actor, 'finance.payments.view', $school),
            // UAT-C3: the payments standing on this charge now (after any
            // corrections); ORG.7G-1: only those the reader may open, plus the
            // amount of the rest.
            'payments' => $payments === null ? null : $payments['payments'],
            'otherPaymentsAmount' => $payments === null ? null : $payments['otherAmount'],
        ]);
    }

    /** @return array<string, mixed> */
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
     * Ledger internals (accounts, journal entries) only for a whole-School
     * reader: a Campus reader holds no ledger authority.
     *
     * @return array<string, mixed>
     */
    private function presentDetail(ChargeDetail $charge, CampusScope $scope): array
    {
        $internals = $scope->isWholeSchool();

        return [
            'id' => $charge->chargeId,
            'studentId' => $charge->studentId,
            'academicYearId' => $charge->academicYearId,
            'description' => $charge->description,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'dueDate' => $charge->dueDate?->toDateString(),
            'receivableLedgerAccountId' => $internals ? $charge->receivableLedgerAccountId : null,
            'revenueLedgerAccountId' => $internals ? $charge->revenueLedgerAccountId : null,
            'journalEntryId' => $internals ? $charge->journalEntryId : null,
            'cancelledAt' => $charge->cancelledAt?->toIso8601String(),
            'cancellationJournalEntryId' => $internals ? $charge->cancellationJournalEntryId : null,
            'createdAt' => $charge->createdAt->toIso8601String(),
        ];
    }
}
