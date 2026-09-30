<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Application\Exceptions\LateFeeRunNotFoundException;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * FEE.5 (ADR 0062 §16, §19): late-fee runs, their items and a charge's
 * late-fee links, read under the existing `finance.charges.view` (run
 * results are charges; the FEE.2 precedent) -- no new capability. Items
 * are paginated; another School's id is a plain not-found.
 */
class LateFeeReadService
{
    use AuthorizesCapability;

    public function __construct(private readonly TenantContext $context) {}

    /** @return Collection<int, LateFeeRun> */
    public function listRuns(School $school, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, fn () => LateFeeRun::query()
            ->where('school_id', $school->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get());
    }

    public function getRun(School $school, string $runId, User $actor): LateFeeRun
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        $run = Str::isUuid($runId)
            ? $this->context->withSchool($school, fn () => LateFeeRun::query()->where('school_id', $school->id)->find($runId))
            : null;

        return $run ?? throw new LateFeeRunNotFoundException($runId);
    }

    /**
     * @param  array{preview_result?: string, execution_status?: string}  $filters
     * @return LengthAwarePaginator<int, LateFeeRunItem>
     */
    public function listItems(School $school, string $runId, array $filters, int $page, User $actor, int $perPage = 50): LengthAwarePaginator
    {
        $run = $this->getRun($school, $runId, $actor);

        return $this->context->withSchool($school, fn () => LateFeeRunItem::query()
            ->where('late_fee_run_id', $run->id)
            ->when(isset($filters['preview_result']), fn ($q) => $q->where('preview_result', $filters['preview_result']))
            ->when(isset($filters['execution_status']), fn ($q) => $q->where('execution_status', $filters['execution_status']))
            ->orderBy('due_date')
            ->orderBy('source_charge_id')
            ->paginate($perPage, ['*'], 'page', $page));
    }

    /**
     * The late-fee links of one charge in both directions: late fees raised
     * ON it (it is the source) and, if it is itself a late fee, its source.
     *
     * @return array{lateFees: Collection<int, LateFeeAssessment>, sourceOf: LateFeeAssessment|null}
     */
    public function linksForCharge(School $school, string $chargeId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, fn () => [
            'lateFees' => LateFeeAssessment::query()->where('source_charge_id', $chargeId)->orderBy('created_at')->get(),
            'sourceOf' => LateFeeAssessment::query()->where('charge_id', $chargeId)->first(),
        ]);
    }
}
