<?php

namespace App\Domain\Fees\Application\Sources;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeResult;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\InvalidChargeException;
use App\Domain\Fees\Application\Exceptions\SourceChargeFeeHeadUnavailableException;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * OPF (ADR 0067 §8, §17, D3, D4, D6, D9): the trusted EVENT-charge seam. An
 * operational source's application service -- after checking its OWN
 * capability (for Library, `library.circulation.manage` at check-in and
 * `library.fines.void` to void) -- asks FEE to assess, or cancel, one
 * Student charge it computed under its own policy. Beside the optional-
 * selection seam, never instead of it.
 *
 * - **Trusted, authorization-neutral** like ChargeService and the selection
 *   seam: no `finance.*` check, none granted. Only an explicit allow-list of
 *   source services calls it (OperationalFeeSourceArchitectureGuardTest); it
 *   has no route. It is the only way a source module reaches ChargeService.
 * - **FEE owns the charge and the ledger.** The ledger destination comes
 *   from an ACTIVE fee head of the School; the charge is a Student charge
 *   (`charges.student_id`), INR, positive, posted through ChargeService.
 * - **No idempotency of its own** (ChargeService::assess() has none): the
 *   source makes it exactly-once with its own unique evidence row, under its
 *   own row lock, in the same transaction (Library: `library_fines`).
 * - **Cancellation is ChargeService::cancel():** a charge with payment
 *   allocations or live adjustments still refuses, so only an unpaid,
 *   unwaived charge can be cancelled. No refund.
 * - **Audited by FEE** with the source and actor; never reads the source.
 */
class FeeSourceChargeService
{
    private const AMOUNT_PATTERN = '/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/';

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function assessForSource(School $school, FeeSourceChargeData $data, FeeChargeSource $source, ?User $actor): ChargeResult
    {
        if (! preg_match(self::AMOUNT_PATTERN, $data->amount) || bccomp($data->amount, '0', 2) <= 0) {
            throw new InvalidChargeException('A source charge amount must be positive, with at most two decimal places.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $data, $source, $actor): ChargeResult {
            $head = FeeHead::query()->where('school_id', $school->id)->sharedLock()->find($data->feeHeadId);
            if ($head === null || ! $head->isActive()) {
                throw new SourceChargeFeeHeadUnavailableException($data->feeHeadId);
            }

            $charge = $this->charges->assess($school, new AssessChargeData(
                studentId: $data->studentId,
                academicYearId: $data->academicYearId,
                description: $data->description,
                amount: Money::of($data->amount, $head->currency),
                receivableLedgerAccountId: $head->receivable_ledger_account_id,
                revenueLedgerAccountId: $head->revenue_ledger_account_id,
            ), $actor);

            $this->audit->school($school, 'charge.source_assessed', actor: $actor, metadata: [
                'chargeId' => $charge->chargeId,
                'studentId' => $charge->studentId,
                'academicYearId' => $charge->academicYearId,
                'feeHeadId' => $head->id,
                ...$source->toAudit(),
            ]);

            return $charge;
        }));
    }

    /** Cancels a source's own event charge as part of its void (the source records the void first). */
    public function cancelForSource(School $school, string $chargeId, FeeChargeSource $source, ?User $actor, string $reason): ChargeResult
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $chargeId, $source, $actor, $reason): ChargeResult {
            $charge = $this->charges->cancel($school, $chargeId, $actor, $reason);

            $this->audit->school($school, 'charge.source_cancelled', actor: $actor, metadata: [
                'chargeId' => $charge->chargeId,
                ...$source->toAudit(),
            ]);

            return $charge;
        }));
    }

    /** @return array{id: string, code: string, name: string}|null an active fee head's summary (no accounts, no amounts), for a source's mapping validation */
    public function chargeableFeeHead(School $school, string $feeHeadId): ?array
    {
        $head = $this->context->withSchool($school, fn () => FeeHead::query()->where('school_id', $school->id)->find($feeHeadId));

        return $head === null || ! $head->isActive() ? null : ['id' => $head->id, 'code' => $head->code, 'name' => $head->name];
    }
}
