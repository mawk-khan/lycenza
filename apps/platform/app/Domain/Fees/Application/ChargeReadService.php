<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Phase 0G.4 -- the sole authorized read path for `charges`, mirroring
 * `App\Domain\Finance\Application\LedgerReadService`'s exact
 * disclosure-boundary/authorization/audit discipline. Every result is
 * a typed DTO (`ChargeSummary`/`ChargeDetail`), never the raw `Charge`
 * Eloquent model.
 *
 * Single capability, `finance.charges.view`, checked BEFORE any query
 * runs. ORG.7G-1 (ADR 0076 §50): it is Campus-adopted, so the check
 * RESOLVES the actor's CampusScope (403 when they hold it nowhere) and both
 * reads restrict to it in SQL, before any row is returned: a Campus scope
 * reaches only Charges whose STORED Campus it covers (never a NULL-Campus,
 * whole-School Charge, never by the Student's current Campus), and an
 * out-of-scope Charge is the same "not found" as an unknown one.
 *
 * `charges` inherits Finance's existing Highly Sensitive classification
 * (docs/modules/FINANCE.md "Data classification" -- reconfirmed, not
 * narrowed, by 0G.3; this checkpoint does not reopen it for the same
 * reason 0G.3 didn't). Every successful read is audited, one event per
 * call, never per row: `charge.list_viewed`, `charge.detail_viewed`.
 */
class ChargeReadService
{
    use AuthorizesCampusScope;

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
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, function () use ($school, $scope, $query, $actor) {
            $builder = $scope->restrict(Charge::query()->where('school_id', $school->id))
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
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, function () use ($school, $scope, $chargeId, $actor) {
            $charge = Str::isUuid($chargeId) ? $scope->restrict(Charge::query()->where('school_id', $school->id))->find($chargeId) : null;

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
