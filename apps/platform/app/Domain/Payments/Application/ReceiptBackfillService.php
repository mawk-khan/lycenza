<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\Payment;
use App\Models\School;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FEE.4 (ADR 0062 §17.4, owner decision I2): the one-time, operator-invoked
 * receipt backfill for Payments settled before FEE.4. Trusted -- reached
 * only by `finance:receipts-backfill {school}`, never an HTTP route, a
 * migration or a deploy step.
 *
 * - One named School. It must be operational (rule 86, ADR 0047): a
 *   suspended or provisioning School is refused up front, and each receipt
 *   re-checks the lifecycle inside its own transaction
 *   (`SchoolOperationalGuard::holdOperational`, FOR SHARE), so a
 *   suspension mid-run stops the rest. Nothing is replayed later; the
 *   operator runs it again after the School resumes.
 * - Candidates: the School's Payments without a receipt, in deterministic
 *   `(settled_at, id)` order, in batches.
 * - Each receipt comes from `ReceiptIssuer` in its own transaction: the
 *   series is the FY of the Payment's HISTORICAL `settled_at`; the number
 *   is the next one of that live series (issuance order -- an older
 *   Payment backfilled later may carry a higher number, ADR 0062
 *   correction 2026-09-30); `issued_at` is the real backfill time, never
 *   backdated. Audited `payment_receipt.backfilled`.
 * - Idempotent: a Payment that already has a receipt is skipped (the
 *   issuer re-checks under the series lock; `payment_receipts_payment_unique`
 *   is the backstop). Payments are never edited; receipts are never
 *   renumbered; counters are never reset.
 */
class ReceiptBackfillService
{
    private const BATCH = 200;

    public function __construct(
        private readonly ReceiptIssuer $issuer,
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
    ) {}

    /** @return array{issued: int, skipped: int} */
    public function backfill(School $school): array
    {
        if (! $this->operational->isOperational($school->id)) {
            throw new SchoolNotOperationalException;
        }

        $issued = 0;
        $skipped = 0;
        $after = null;

        do {
            $batch = $this->context->withSchool($school, fn () => Payment::query()
                ->where('school_id', $school->id)
                ->whereDoesntHave('receipt')
                ->when($after !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->where('settled_at', '>', $after['settled_at'])
                    ->orWhere(fn ($t) => $t->where('settled_at', $after['settled_at'])->where('id', '>', $after['id']))))
                ->orderBy('settled_at')
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get());

            foreach ($batch as $payment) {
                $result = $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $payment) {
                    $this->operational->requireOperational($school->id);

                    return $this->issuer->issue($school, $payment, ReceiptIssuer::ISSUANCE_BACKFILL);
                }));

                $result['issued'] ? $issued++ : $skipped++;
                $after = ['settled_at' => $payment->settled_at->format('Y-m-d H:i:s'), 'id' => $payment->id];
            }
        } while ($batch->count() === self::BATCH);

        return ['issued' => $issued, 'skipped' => $skipped];
    }
}
