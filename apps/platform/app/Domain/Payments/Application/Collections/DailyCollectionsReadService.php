<?php

namespace App\Domain\Payments\Application\Collections;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * LC-4 (M4): the operational collections list -- "what payments did we
 * receive today?" -- under `finance.payments.view` (the Cashier holds it).
 *
 * - **Day membership** is the School-local calendar date the Payment was
 *   RECORDED (`payments.created_at` in `schools.timezone`), never a UTC
 *   date. The received date the cashier entered (`settled_at`, which may
 *   be earlier) is shown beside it.
 * - **Gross received** is the sum of the original Payment amounts of every
 *   matching receipt (not the page). Posted Payments never change, so a
 *   later reallocation, reversal or refund never removes a receipt from
 *   the day it was recorded.
 * - **Status** is read from the correction records (ADR 0031 amendment
 *   §3): `reversed` once a reversal exists; `refunded` when refunds equal
 *   the amount; `partly_refunded` when some was refunded; otherwise
 *   `received`. A reallocation only moves where the money sits, so it
 *   never changes the status (it is flagged separately).
 * - **Currently retained** = gross minus what was refunded or reversed
 *   since, for the same receipts -- whenever that happened. It is not the
 *   day's cash movement.
 *
 * No Student, receipt or reference text is audited. The manual payment
 * reference is never read (rule 91).
 */
class DailyCollectionsReadService
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'finance.payments.view';

    public const string RECEIVED = 'received';

    public const string PARTLY_REFUNDED = 'partly_refunded';

    public const string REFUNDED = 'refunded';

    public const string REVERSED = 'reversed';

    public const int PER_PAGE = 25;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function list(School $school, User $actor, DailyCollectionsQuery $query): DailyCollections
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $actor, $query): DailyCollections {
            $timezone = SchoolTimezone::resolve($school);
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $query->from, $timezone)->utc();
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $query->to, $timezone)->addDay()->utc();

            $chargeIds = $query->studentIds === null ? null : $this->charges->chargeIdsForStudents($school, $query->studentIds);

            /** @var Builder<Payment> $base */
            $base = Payment::query()
                ->where('payments.school_id', $school->id)
                ->where('payments.created_at', '>=', $start->toDateTimeString())
                ->where('payments.created_at', '<', $end->toDateTimeString())
                ->when($query->receipt !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereHas('receipt', fn (Builder $r) => $r->where('receipt_number', 'ilike', '%'.addcslashes($query->receipt, '%_\\').'%'))
                    ->orWhere('payments.provider_payment_reference', $query->receipt)))
                ->when($query->method === 'provider', fn (Builder $q) => $q->where('payments.source', 'provider'))
                ->when($query->method !== null && $query->method !== 'provider', fn (Builder $q) => $q->where('payments.method', $query->method))
                ->when($chargeIds !== null, fn (Builder $q) => $q->whereIn('payments.id', PaymentAllocation::query()->whereIn('charge_id', $chargeIds)->select('payment_id')));

            $gross = (clone $base)->toBase()->selectRaw('payments.currency, count(*) as receipts, sum(payments.amount) as gross')
                ->groupBy('payments.currency')->get()->keyBy('currency');
            $notRetained = PaymentCorrection::query()->where('school_id', $school->id)
                ->whereIn('kind', [PaymentCorrection::REFUND, PaymentCorrection::REVERSAL])
                ->whereIn('payment_id', (clone $base)->select('payments.id'))
                ->toBase()->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency');

            $totals = [];
            foreach ($gross as $currency => $row) {
                $out = (string) ($notRetained[$currency] ?? '0.00');
                $totals[] = [
                    'currency' => (string) $currency,
                    'receipts' => (int) $row->receipts,
                    'gross' => bcadd((string) $row->gross, '0', 2),
                    'retained' => bcsub((string) $row->gross, $out, 2),
                ];
            }

            $page = (clone $base)->with('receipt:id,school_id,payment_id,receipt_number')
                ->orderByDesc('payments.created_at')->orderByDesc('payments.id')
                ->paginate(self::PER_PAGE, ['payments.*'], 'page', $query->page);

            $rows = $this->rows($school, $page->getCollection()->all(), $timezone);

            $this->audit->school($school, 'payment.list_viewed', actor: $actor, metadata: [
                'resultCount' => count($rows),
                'from' => $query->from,
                'to' => $query->to,
                'filtered' => $query->receipt !== null || $query->method !== null || $query->studentIds !== null,
            ]);

            return new DailyCollections(
                new LengthAwarePaginator($rows, $page->total(), $page->perPage(), $page->currentPage(), ['path' => $page->path()]),
                $totals,
            );
        });
    }

    /**
     * Status, Students and receipt for one page -- three queries in all.
     *
     * @param  list<Payment>  $payments
     * @return list<DailyCollectionRow>
     */
    private function rows(School $school, array $payments, \DateTimeZone $timezone): array
    {
        $ids = array_map(fn (Payment $p) => $p->id, $payments);
        $corrections = PaymentCorrection::query()->where('school_id', $school->id)->whereIn('payment_id', $ids)
            ->get(['payment_id', 'kind', 'amount'])->groupBy('payment_id');
        $allocations = PaymentAllocation::query()->where('school_id', $school->id)->whereIn('payment_id', $ids)
            ->orderBy('created_at')->orderBy('id')->get(['payment_id', 'charge_id'])->groupBy('payment_id');
        $studentOf = $this->charges->studentIdsForCharges($school, $allocations->flatten()->pluck('charge_id')->all());

        return array_map(function (Payment $p) use ($corrections, $allocations, $studentOf, $timezone): DailyCollectionRow {
            $own = $corrections->get($p->id, collect());
            $refunded = $own->where('kind', PaymentCorrection::REFUND)->reduce(fn (string $sum, $c) => bcadd($sum, (string) $c->amount, 2), '0.00');
            $status = match (true) {
                $own->contains('kind', PaymentCorrection::REVERSAL) => self::REVERSED,
                bccomp($refunded, (string) $p->amount, 2) >= 0 => self::REFUNDED,
                bccomp($refunded, '0.00', 2) > 0 => self::PARTLY_REFUNDED,
                default => self::RECEIVED,
            };

            return new DailyCollectionRow(
                paymentId: $p->id,
                receiptNumber: $p->receipt?->receipt_number,
                amount: (string) $p->amount,
                currency: (string) $p->currency,
                recordedAt: CarbonImmutable::parse($p->created_at)->setTimezone($timezone),
                receivedOn: CarbonImmutable::parse($p->settled_at)->setTimezone($timezone)->toDateString(),
                source: (string) $p->source,
                provider: $p->provider,
                method: $p->method,
                status: $status,
                refunded: $refunded,
                reallocated: $own->contains('kind', PaymentCorrection::REALLOCATION),
                studentIds: $allocations->get($p->id, collect())->map(fn ($a) => $studentOf[$a->charge_id] ?? null)->filter()->unique()->values()->all(),
                recordedByUserId: $p->recorded_by_user_id,
            );
        }, $payments);
    }
}
