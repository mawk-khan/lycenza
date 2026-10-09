<?php

namespace App\Domain\Payments\Application\Portal;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Domain\Identity\Application\Portal\ActingGuardian;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Domain\Payments\Application\Charges\ChargeState;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Money;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * POR.3 (ADR 0070 §26): one linked Student's fees for an already-resolved
 * ActingGuardian -- read-only, Payments-owned (it depends on allocations), a
 * SEPARATE entry point from the staff StudentFeeStatementReadService, which
 * stays unchanged and staff-only.
 *
 * - Authority: GuardianStudentScope (live). Fees' charge read EMBEDS the
 *   scope's predicate (`statementLinesForStudentWithin`), so authorization
 *   and read are one statement. Anything else is the same 404.
 * - Balances: the authoritative per-charge state (ChargeStateReader: amount -
 *   allocations - live adjustments; nothing owed once cancelled) -- never a
 *   second calculation.
 * - History: the charges of the School's ACTIVE academic year, plus any
 *   charge of another year (earlier, or already assessed for a later year)
 *   still OUTSTANDING. Settled or cancelled charges of other years are not
 *   shown. So the outstanding total equals the Student's
 *   authoritative all-years outstanding; nothing owed is hidden.
 * - Shared payments: a Payment may pay several Students' charges. A
 *   Guardian never sees a Payment's total, its receipt document, another
 *   Student's allocation, or how many Students it covered -- only "applied
 *   to this Student": that Student's own allocations and their sum, beside
 *   the receipt NUMBER as a reference (the whole-payment receipt stays a
 *   School document).
 * - Posted Payments are immutable (no refund/void/reversal exists, rule 91);
 *   charge cancellation and adjustment cancellation are reflected through the
 *   charge state. Nothing here writes anything except the audit.
 * - Audit: `fee_statement.guardian.viewed` and
 *   `payment_allocation.guardian.viewed`, one per successful read (ids and
 *   counts, never an amount or description). Denied reads write nothing.
 *
 * Dates are the School's calendar days (`settled_at` is stored as School-local
 * midnight in UTC). MFA is the route's (`mfa-page`); PortalAvailability and
 * `portal.fees.view` are re-asserted here.
 */
final class GuardianFeeReadService
{
    public const STATEMENT_VIEWED = 'fee_statement.guardian.viewed';

    public const PAYMENT_VIEWED = 'payment_allocation.guardian.viewed';

    public const CAPABILITY = 'portal.fees.view';

    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianStudentScope $scope,
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly AuditRecorder $audit,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /** @return list<array{id: string, name: string}> */
    public function students(School $school, ActingGuardian $guardian): array
    {
        PortalAvailability::assertAvailable();

        return $this->scope->eligibleStudents($school, $guardian->guardianId);
    }

    /**
     * @return array{student: array{id: string, name: string}, currency: string, lines: list<array<string, mixed>>, totals: array{charged: string, adjusted: string, paid: string, outstanding: string}}
     */
    public function statement(School $school, ActingGuardian $guardian, User $actor, string $studentId): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $studentId): array {
            $student = $this->student($school, $guardian, $studentId);
            [$facts, $states, $activeYearId] = $this->visible($school, $guardian, $studentId);
            $chargeIds = array_map(fn (ChargeStatementLine $l) => $l->chargeId, $facts);

            $allocations = $chargeIds === [] ? collect() : PaymentAllocation::query()
                ->where('school_id', $school->id)->whereIn('charge_id', $chargeIds)->get();
            $payments = Payment::query()->where('school_id', $school->id)
                ->whereIn('id', $allocations->pluck('payment_id')->unique()->all())->with('receipt')->get()->keyBy('id');

            $currency = $facts[0]->currency ?? 'INR';
            $totals = ['charged' => Money::of('0.00', $currency), 'adjusted' => Money::of('0.00', $currency), 'paid' => Money::of('0.00', $currency), 'outstanding' => Money::of('0.00', $currency)];
            $lines = [];
            foreach ($facts as $fact) {
                $state = $states[$fact->chargeId];
                $outstanding = $state->outstanding();
                if (! $state->cancelled) {
                    $totals['charged'] = $totals['charged']->add($state->amount);
                    $totals['adjusted'] = $totals['adjusted']->add($state->adjusted);
                }
                $totals['paid'] = $totals['paid']->add($state->allocated);
                $totals['outstanding'] = $totals['outstanding']->add($outstanding);

                $lines[] = [
                    'description' => $fact->description,
                    'feeHeadName' => $fact->feeHeadName,
                    'billingPeriodLabel' => $fact->billingPeriodLabel,
                    'dueDate' => $fact->dueDate,
                    'currentYear' => $activeYearId !== null && $fact->academicYearId === $activeYearId,
                    'status' => $state->cancelled ? 'cancelled' : ($outstanding->isZero() ? 'settled' : 'outstanding'),
                    'amount' => $state->amount->amount(),
                    'adjustedTotal' => $state->adjusted->amount(),
                    'paidTotal' => $state->allocated->amount(),
                    'outstanding' => $outstanding->amount(),
                    // Only THIS charge's allocations: the amount applied here, never a Payment's total.
                    'payments' => $allocations->where('charge_id', $fact->chargeId)
                        ->sortBy(fn (PaymentAllocation $a) => ($payments->get($a->payment_id)?->settled_at->format('Y-m-d H:i:s') ?? '').$a->payment_id)
                        ->map(fn (PaymentAllocation $a): array => [
                            'paymentId' => $a->payment_id,
                            'settledOn' => $payments->get($a->payment_id)?->settled_at->copy()->setTimezone($school->timezone ?: 'UTC')->toDateString(),
                            'method' => $payments->get($a->payment_id)?->method,
                            'receiptNumber' => $payments->get($a->payment_id)?->receipt?->receipt_number,
                            'appliedAmount' => Money::of($a->amount, $a->currency)->amount(),
                        ])->values()->all(),
                ];
            }

            $this->audit->school($school, self::STATEMENT_VIEWED, actor: $actor, metadata: [
                'guardianId' => $guardian->guardianId,
                'accountLinkId' => $guardian->accountLinkId,
                'studentId' => $studentId,
                'academicYearId' => $activeYearId,
                'lineCount' => count($lines),
                'surface' => 'guardian_portal',
            ]);

            return [
                'student' => $student,
                'currency' => $currency,
                'lines' => $lines,
                'totals' => array_map(fn (Money $m) => $m->amount(), $totals),
            ];
        });
    }

    /**
     * One Payment as applied to this Student -- only when it has an allocation
     * to one of this Student's visible charges; otherwise the same 404.
     *
     * @return array{student: array{id: string, name: string}, paymentId: string, settledOn: string, method: ?string, receiptNumber: ?string, currency: string, appliedTotal: string, lines: list<array<string, mixed>>}
     */
    public function payment(School $school, ActingGuardian $guardian, User $actor, string $studentId, string $paymentId): array
    {
        PortalAvailability::assertAvailable();
        $this->assertSelf($school, $guardian, $actor);

        return $this->context->withSchool($school, function () use ($school, $guardian, $actor, $studentId, $paymentId): array {
            $student = $this->student($school, $guardian, $studentId);
            [$facts] = $this->visible($school, $guardian, $studentId);
            $byCharge = collect($facts)->keyBy(fn (ChargeStatementLine $l) => $l->chargeId);

            $allocations = $byCharge->isEmpty() ? collect() : PaymentAllocation::query()
                ->where('school_id', $school->id)->where('payment_id', $paymentId)
                ->whereIn('charge_id', $byCharge->keys()->all())->orderBy('charge_id')->get();
            $payment = $allocations->isEmpty() ? null
                : Payment::query()->where('school_id', $school->id)->whereKey($paymentId)->with('receipt')->first();
            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class);
            }

            $applied = Money::of('0.00', $payment->currency);
            $lines = [];
            foreach ($allocations as $allocation) {
                $fact = $byCharge->get($allocation->charge_id);
                $amount = Money::of($allocation->amount, $allocation->currency);
                $applied = $applied->add($amount);
                $lines[] = [
                    'description' => $fact->description,
                    'feeHeadName' => $fact->feeHeadName,
                    'billingPeriodLabel' => $fact->billingPeriodLabel,
                    'appliedAmount' => $amount->amount(),
                ];
            }

            $this->audit->school($school, self::PAYMENT_VIEWED, actor: $actor, metadata: [
                'guardianId' => $guardian->guardianId,
                'accountLinkId' => $guardian->accountLinkId,
                'studentId' => $studentId,
                'paymentId' => $payment->id,
                'allocationCount' => count($lines),
                'surface' => 'guardian_portal',
            ]);

            return [
                'student' => $student,
                'paymentId' => $payment->id,
                'settledOn' => $payment->settled_at->copy()->setTimezone($school->timezone ?: 'UTC')->toDateString(),
                'method' => $payment->method,
                'receiptNumber' => $payment->receipt?->receipt_number,
                'currency' => $payment->currency,
                'appliedTotal' => $applied->amount(),
                'lines' => $lines,
            ];
        });
    }

    /** @return array{id: string, name: string} */
    private function student(School $school, ActingGuardian $guardian, string $studentId): array
    {
        return collect($this->scope->eligibleStudents($school, $guardian->guardianId))->firstWhere('id', $studentId)
            ?? throw (new ModelNotFoundException)->setModel(Student::class);
    }

    /**
     * The Student's charges a Guardian may see: the active year's, plus any
     * other year's still owed -- read with the scope predicate embedded.
     *
     * @return array{0: list<ChargeStatementLine>, 1: array<string, ChargeState>, 2: ?string}
     */
    private function visible(School $school, ActingGuardian $guardian, string $studentId): array
    {
        $activeYearId = AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');
        $all = $this->charges->statementLinesForStudentWithin($school, $studentId, $this->scope->eligibleStudentIdsQuery($school, $guardian->guardianId));
        $states = $all === [] ? [] : $this->states->forCharges($school, array_map(fn (ChargeStatementLine $l) => $l->chargeId, $all));

        $facts = array_values(array_filter($all, fn (ChargeStatementLine $l) => ($activeYearId !== null && $l->academicYearId === $activeYearId)
            || ! $states[$l->chargeId]->outstanding()->isZero()));

        return [$facts, $states, $activeYearId];
    }

    private function assertSelf(School $school, ActingGuardian $guardian, User $actor): void
    {
        if ($guardian->userId !== $actor->id || $guardian->schoolId !== $school->id) {
            throw (new ModelNotFoundException)->setModel(Student::class);
        }

        // Defence in depth (rule 6): the capability is checked here too, not
        // only by the route -- a future non-route caller cannot skip it.
        if (! $this->capabilities->canInSchool($actor, self::CAPABILITY, $school)) {
            throw new GuardianPortalAccessDeniedException;
        }
    }
}
