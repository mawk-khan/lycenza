<?php

namespace App\Domain\Library\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Fees\Application\Exceptions\ChargeHasActiveAdjustmentsException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\SourceChargeFeeHeadUnavailableException;
use App\Domain\Fees\Application\Sources\FeeChargeSource;
use App\Domain\Fees\Application\Sources\FeeSourceChargeData;
use App\Domain\Fees\Application\Sources\FeeSourceChargeService;
use App\Domain\Library\Application\Exceptions\LibraryFineAlreadyVoidedException;
use App\Domain\Library\Application\Exceptions\LibraryFineNotFoundException;
use App\Domain\Library\Application\Exceptions\LibraryFineNotVoidableException;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryFineVoid;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Uid\UuidV7;

/**
 * OPF.4 (ADR 0067 §17, D3, D4, D6): Library overdue fines -- the ONLY writer
 * of `library_fines` and `library_fine_voids`, and the only caller of FEE's
 * event-charge seam (FeeSourceChargeService). Library never reaches FEE's
 * charge service itself; FEE never reads Library.
 *
 * - **Assessed once, at check-in.** LibraryLoanService::checkIn() calls
 *   assessOnCheckIn() inside its transaction, holding the loan row lock its
 *   conditional UPDATE took. The final overdue duration decides one fine
 *   (one per loan and kind, `library_fines_one_per_loan_kind`); nothing
 *   accrues daily, and there is no separate assessment path.
 * - **The formula (calculate()):** overdue days = each started 24-hour
 *   period after `due_at` (ceil of the exact elapsed time / 24 h; zero when
 *   returned at or before `due_at`); chargeable days = overdue days minus
 *   the version's grace days (never below zero); amount = chargeable days x
 *   daily rate, capped at the version's cap. INR, exact decimals. The
 *   database re-derives it on insert (`library_fines_guard`).
 * - **The governing policy version** at assessment is the School's highest
 *   version; the fine names it, and versions are immutable.
 * - **Not applicable, never a failed return:** overdue with no policy or a
 *   disabled one (`no_policy`), within grace (`within_grace`), no active
 *   academic year (`no_active_academic_year`), or a policy fee head no
 *   longer active (`fee_head_unavailable`) are audited and the check-in
 *   completes. Any other failure rolls the check-in back.
 * - **Academic year:** the School's active year at assessment
 *   (CurrentAcademicYearResolver), as Canteen charges and OPF.1/OPF.2's new
 *   intent use; loans carry no year.
 * - **Void (D4):** voids an erroneous fine -- a LibraryFineVoid row, then
 *   FEE cancels the unpaid charge, in one transaction (the database makes the
 *   two inseparable). A paid or waived fine is refused; nothing is refunded
 *   or deleted. Waivers are FEE concessions (`waiver`), not done here.
 * - **Authorization** is the caller's: `library.circulation.manage` for
 *   check-in, `library.fines.void` to void (controllers). No Finance
 *   capability is granted or needed (D9).
 */
class LibraryFineService
{
    public const NOT_APPLICABLE_NO_POLICY = 'no_policy';

    public const NOT_APPLICABLE_WITHIN_GRACE = 'within_grace';

    public const NOT_APPLICABLE_NO_ACTIVE_YEAR = 'no_active_academic_year';

    public const NOT_APPLICABLE_FEE_HEAD_UNAVAILABLE = 'fee_head_unavailable';

    private const DAY_MICROSECONDS = 86_400_000_000;

    public function __construct(
        private readonly FeeSourceChargeService $fees,
        private readonly LibraryFinePolicyService $policies,
        private readonly CurrentAcademicYearResolver $years,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{overdue_days: int, chargeable_days: int, amount: string}
     */
    public static function calculate(Carbon $dueAt, Carbon $checkedInAt, LibraryFinePolicy $policy): array
    {
        if (! $policy->isActive()) {
            throw new LogicException('Only an active fine policy version has a formula.');
        }
        $late = (int) $checkedInAt->format('Uu') - (int) $dueAt->format('Uu');
        $overdue = $late <= 0 ? 0 : intdiv($late + self::DAY_MICROSECONDS - 1, self::DAY_MICROSECONDS);
        $chargeable = max($overdue - (int) $policy->grace_days, 0);
        $amount = bcmul((string) $chargeable, (string) $policy->daily_rate, 2);
        if ($policy->max_amount !== null && bccomp($amount, (string) $policy->max_amount, 2) > 0) {
            $amount = bcadd((string) $policy->max_amount, '0', 2);
        }

        return ['overdue_days' => $overdue, 'chargeable_days' => $chargeable, 'amount' => $amount];
    }

    /**
     * Called by LibraryLoanService::checkIn() inside its transaction, after
     * the loan was marked returned (its row lock held). Returns the fine, or
     * null when none applies.
     */
    public function assessOnCheckIn(LibraryLoan $loan, ?User $actor): ?LibraryFine
    {
        if ($loan->status !== 'returned' || $loan->checked_in_at === null) {
            throw new LogicException('A fine is assessed only for a returned loan.');
        }
        if (! $loan->checked_in_at->greaterThan($loan->due_at)) {
            return null; // returned on time: no fine, nothing to say
        }

        $school = $loan->school;
        $existing = LibraryFine::query()->where('library_loan_id', $loan->id)->where('kind', LibraryFine::KIND_OVERDUE)->first();
        if ($existing !== null) {
            return $existing;
        }

        $policy = $this->policies->current($school);
        if ($policy === null || ! $policy->isActive()) {
            $this->notApplicable($school, $loan, $policy, self::NOT_APPLICABLE_NO_POLICY, $actor);

            return null;
        }

        $calculated = self::calculate($loan->due_at, $loan->checked_in_at, $policy);
        if ($calculated['chargeable_days'] === 0) {
            $this->notApplicable($school, $loan, $policy, self::NOT_APPLICABLE_WITHIN_GRACE, $actor);

            return null;
        }

        $year = $this->years->tryResolve($school);
        if ($year === null) {
            $this->notApplicable($school, $loan, $policy, self::NOT_APPLICABLE_NO_ACTIVE_YEAR, $actor);

            return null;
        }

        $fineId = (string) new UuidV7;
        try {
            // One savepoint for the charge and its evidence: a lost race or an unavailable head undoes both.
            $fine = DB::transaction(function () use ($school, $loan, $policy, $calculated, $year, $fineId, $actor): LibraryFine {
                $charge = $this->fees->assessForSource($school, new FeeSourceChargeData(
                    studentId: $loan->student_id,
                    academicYearId: $year->id,
                    feeHeadId: (string) $policy->fee_head_id,
                    amount: $calculated['amount'],
                    description: 'Library overdue fine',
                ), FeeChargeSource::libraryFine($fineId), $actor);

                return LibraryFine::query()->create([
                    'id' => $fineId,
                    'school_id' => $school->id,
                    'library_loan_id' => $loan->id,
                    'student_id' => $loan->student_id,
                    'kind' => LibraryFine::KIND_OVERDUE,
                    'library_fine_policy_id' => $policy->id,
                    'fee_head_id' => $policy->fee_head_id,
                    'academic_year_id' => $year->id,
                    'due_at' => $loan->due_at,
                    'checked_in_at' => $loan->checked_in_at,
                    'overdue_days' => $calculated['overdue_days'],
                    'chargeable_days' => $calculated['chargeable_days'],
                    'amount' => $calculated['amount'],
                    'currency' => 'INR',
                    'charge_id' => $charge->chargeId,
                    'assessed_by_user_id' => $actor?->id,
                ]);
            });
        } catch (SourceChargeFeeHeadUnavailableException) {
            $this->notApplicable($school, $loan, $policy, self::NOT_APPLICABLE_FEE_HEAD_UNAVAILABLE, $actor);

            return null;
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'library_fines_one_per_loan_kind')) {
                throw $e;
            }

            return LibraryFine::query()->where('library_loan_id', $loan->id)->where('kind', LibraryFine::KIND_OVERDUE)->firstOrFail();
        }

        $this->audit->school($school, 'library.fine.assessed', actor: $actor, subject: $fine, metadata: [
            'libraryFineId' => $fine->id,
            'libraryLoanId' => $loan->id,
            'studentId' => $loan->student_id,
            'chargeId' => $fine->charge_id,
            'libraryFinePolicyId' => $policy->id,
            'policyVersion' => $policy->version,
            'overdueDays' => $fine->overdue_days,
            'chargeableDays' => $fine->chargeable_days,
            'amount' => $fine->amount,
        ]);

        return $fine;
    }

    /** D4: voids an erroneously assessed, still unpaid and unwaived fine, and cancels its charge. Never a refund. */
    public function void(School $school, string $fineId, string $reason, ?User $actor): LibraryFine
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $fineId, $reason, $actor): LibraryFine {
            $fine = LibraryFine::query()->where('school_id', $school->id)->find($fineId) ?? throw new LibraryFineNotFoundException;
            // The loan row serializes every write about its fine (the fine itself is insert-only).
            LibraryLoan::query()->whereKey($fine->library_loan_id)->lockForUpdate()->firstOrFail();
            if (LibraryFineVoid::query()->where('library_fine_id', $fine->id)->exists()) {
                throw new LibraryFineAlreadyVoidedException;
            }

            try {
                $void = DB::transaction(fn () => LibraryFineVoid::query()->create([
                    'school_id' => $school->id,
                    'library_fine_id' => $fine->id,
                    'reason' => mb_substr(trim($reason), 0, 255),
                    'voided_by_user_id' => $actor?->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new LibraryFineAlreadyVoidedException;
            }

            try {
                $this->fees->cancelForSource($school, $fine->charge_id, FeeChargeSource::libraryFine($fine->id), $actor, $void->reason);
            } catch (ChargeHasPaymentAllocationsException) {
                throw new LibraryFineNotVoidableException('its charge has payments allocated');
            } catch (ChargeHasActiveAdjustmentsException) {
                throw new LibraryFineNotVoidableException('its charge has a live concession or adjustment');
            }

            $this->audit->school($school, 'library.fine.voided', actor: $actor, subject: $fine, metadata: [
                'libraryFineId' => $fine->id,
                'libraryLoanId' => $fine->library_loan_id,
                'studentId' => $fine->student_id,
                'chargeId' => $fine->charge_id,
                'libraryFineVoidId' => $void->id,
            ]);

            return $fine;
        }));
    }

    /** An overdue return that was not fined: audited, never a failed return. */
    private function notApplicable(School $school, LibraryLoan $loan, ?LibraryFinePolicy $policy, string $why, ?User $actor): void
    {
        $this->audit->school($school, 'library.fine.not_applicable', actor: $actor, subject: $loan, metadata: [
            'libraryLoanId' => $loan->id,
            'studentId' => $loan->student_id,
            'libraryFinePolicyId' => $policy?->id,
            'policyVersion' => $policy?->version,
            'reason' => $why,
        ]);
    }
}
