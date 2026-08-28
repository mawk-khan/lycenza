<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 0G.4 -- the sole authorized read path for `charges`, mirroring
 * `App\Domain\Finance\Application\LedgerReadService`'s exact
 * disclosure-boundary/authorization/audit discipline. Every result is
 * a typed DTO (`ChargeSummary`/`ChargeDetail`), never the raw `Charge`
 * Eloquent model.
 *
 * Single capability, `finance.charges.view`, gates both read
 * operations here, checked BEFORE any query runs -- a caller lacking
 * the capability learns nothing about $school's charges, not even a
 * count.
 *
 * `charges` inherits Finance's existing Highly Sensitive classification
 * (docs/modules/FINANCE.md "Data classification" -- reconfirmed, not
 * narrowed, by 0G.3; this checkpoint does not reopen it for the same
 * reason 0G.3 didn't). Every successful read is audited, one event per
 * call, never per row: `charge.list_viewed`, `charge.detail_viewed`.
 */
class ChargeReadService
{
    use AuthorizesCapability;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ChargeSummary>
     */
    public function listCharges(School $school, ChargeQuery $query, User $actor): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, function () use ($school, $query, $actor) {
            $builder = Charge::query()
                ->where('school_id', $school->id)
                ->when($query->studentId !== null, fn ($q) => $q->where('student_id', $query->studentId))
                ->when($query->academicYearId !== null, fn ($q) => $q->where('academic_year_id', $query->academicYearId))
                ->when(! $query->includeCancelled, fn ($q) => $q->whereNull('cancelled_at'))
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $paginator = $builder->paginate($query->perPage, ['*'], 'page', $query->page);

            $charges = $paginator->getCollection()->map(fn (Charge $charge) => ChargeSummary::fromModel($charge));

            $this->audit->school($school, 'charge.list_viewed', actor: $actor, metadata: [
                'resultCount' => $charges->count(),
            ]);

            return new LengthAwarePaginator(
                $charges,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                ['path' => $paginator->path()],
            );
        });
    }

    public function getChargeDetail(School $school, string $chargeId, User $actor): ChargeDetail
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, function () use ($school, $chargeId, $actor) {
            $charge = Charge::query()->where('school_id', $school->id)->find($chargeId);

            if ($charge === null) {
                throw new ChargeNotFoundException($chargeId);
            }

            $this->audit->school($school, 'charge.detail_viewed', actor: $actor, subject: $charge, metadata: [
                'chargeId' => $charge->id,
            ]);

            return ChargeDetail::fromModel($charge);
        });
    }
}
