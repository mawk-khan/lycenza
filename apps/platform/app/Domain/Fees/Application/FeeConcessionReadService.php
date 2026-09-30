<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeConcessionNotFoundException;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * FEE.3 (ADR 0062 §19, §21): concessions and their adjustments are Highly
 * Sensitive (they can reveal a family's circumstances) and read under
 * `finance.fee_concessions.view` only. Reads are audited once per call,
 * never per row (the `charge.list_viewed` precedent):
 * `fee_concession.list_viewed`, `fee_concession.viewed`,
 * `fee_adjustment.list_viewed`. A School the actor cannot view learns
 * nothing; another School's id is a plain not-found.
 */
class FeeConcessionReadService
{
    use AuthorizesCapability;

    public const VIEW = 'finance.fee_concessions.view';

    public function __construct(
        private readonly FeeAdjustmentService $adjustments,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * ADR 0062 §14.4: the assessment preview's concession projection --
     * per run item, the total the currently approved standing concessions
     * would post on its instalment amount, and whether that would exceed
     * the charge (the item would then fail closed at execution). A
     * projection only: execution re-reads everything under its locks.
     *
     * @param  iterable<FeeAssessmentRunItem>  $items
     * @return array<string, array{concession: string, net: string, exceeds: bool}> keyed by item id; items without a concession are absent
     */
    public function previewStanding(School $school, string $academicYearId, iterable $items, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $items, $actor) {
            $items = collect($items);
            $periodStarts = FeeStructureInstallment::query()
                ->whereIn('id', $items->pluck('fee_structure_installment_id')->unique()->values()->all())
                ->get()
                ->mapWithKeys(fn (FeeStructureInstallment $i) => [$i->id => $i->period_starts_on->toDateString()]);

            $projection = [];
            foreach ($items as $item) {
                $start = $periodStarts[$item->fee_structure_installment_id] ?? null;
                if ($start === null) {
                    continue;
                }
                $gross = Money::of((string) $item->amount, (string) $item->currency);
                $total = Money::of('0.00', $gross->currency());
                foreach ($this->adjustments->standingConcessionsFor($school, $item->student_id, $academicYearId, $item->fee_head_id, $start) as $concession) {
                    $total = $total->add($this->adjustments->valueOf($concession, $gross));
                }
                if ($total->isPositive()) {
                    $net = $gross->add($total->negated());
                    $projection[$item->id] = ['concession' => $total->amount(), 'net' => $net->isNegative() ? '0.00' : $net->amount(), 'exceeds' => $net->isNegative()];
                }
            }

            $this->audit->school($school, 'fee_concession.list_viewed', actor: $actor, metadata: [
                'resultCount' => count($projection),
                'filters' => ['assessment_preview'],
            ]);

            return $projection;
        });
    }

    /**
     * @param  array{status?: string, scope?: string, student_id?: string, charge_id?: string}  $filters
     * @return LengthAwarePaginator<int, FeeConcession>
     */
    public function list(School $school, array $filters, int $page, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, self::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $filters, $page, $actor, $perPage) {
            $result = FeeConcession::query()
                ->where('school_id', $school->id)
                ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['scope']), fn ($q) => $q->where('scope', $filters['scope']))
                ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
                ->when(isset($filters['charge_id']), fn ($q) => $q->where('charge_id', $filters['charge_id']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $this->audit->school($school, 'fee_concession.list_viewed', actor: $actor, metadata: [
                'resultCount' => count($result->items()),
                'filters' => array_keys($filters),
            ]);

            return $result;
        });
    }

    /** @return array{concession: FeeConcession, adjustments: Collection<int, FeeAdjustment>} */
    public function get(School $school, string $concessionId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $concessionId, $actor) {
            $concession = Str::isUuid($concessionId)
                ? FeeConcession::query()->where('school_id', $school->id)->find($concessionId)
                : null;
            if ($concession === null) {
                throw new FeeConcessionNotFoundException($concessionId);
            }

            $adjustments = FeeAdjustment::query()
                ->where('fee_concession_id', $concession->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $this->audit->school($school, 'fee_concession.viewed', actor: $actor, subject: $concession, metadata: [
                'feeConcessionId' => $concession->id,
            ]);

            return ['concession' => $concession, 'adjustments' => $adjustments];
        });
    }

    /**
     * Adjustments, optionally for one charge or concession (the charge
     * context view), newest first, bounded.
     *
     * @param  array{charge_id?: string, fee_concession_id?: string}  $filters
     * @return Collection<int, FeeAdjustment>
     */
    public function listAdjustments(School $school, array $filters, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, self::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $filters, $actor) {
            $adjustments = FeeAdjustment::query()
                ->where('school_id', $school->id)
                ->when(isset($filters['charge_id']), fn ($q) => $q->where('charge_id', $filters['charge_id']))
                ->when(isset($filters['fee_concession_id']), fn ($q) => $q->where('fee_concession_id', $filters['fee_concession_id']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            $this->audit->school($school, 'fee_adjustment.list_viewed', actor: $actor, metadata: [
                'resultCount' => $adjustments->count(),
                'filters' => array_keys($filters),
            ]);

            return $adjustments;
        });
    }
}
