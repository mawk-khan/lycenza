<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunNotFoundException;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEE.2 (ADR 0062 §19): runs and their items are read under the existing
 * `finance.charges.view` capability (a run's items are the charges it will
 * or did produce) -- no separate FEE read capability exists. Items are
 * paginated; a School the actor cannot view learns nothing.
 */
class FeeAssessmentRunReadService
{
    use AuthorizesCapability;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{fee_structure_id?: string, status?: string}  $filters
     * @return Collection<int, FeeAssessmentRun>
     */
    public function listRuns(School $school, array $filters, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        return $this->context->withSchool($school, fn () => FeeAssessmentRun::query()
            ->where('school_id', $school->id)
            ->when(isset($filters['fee_structure_id']), fn ($q) => $q->where('fee_structure_id', $filters['fee_structure_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get());
    }

    public function getRun(School $school, string $runId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);

        $run = $this->context->withSchool($school, fn () => FeeAssessmentRun::query()->where('school_id', $school->id)->find($runId));

        if ($run === null) {
            throw new FeeAssessmentRunNotFoundException($runId);
        }

        return $run;
    }

    /**
     * @param  array{preview_result?: string, execution_status?: string}  $filters
     * @return LengthAwarePaginator<int, FeeAssessmentRunItem>
     */
    public function listItems(School $school, string $runId, array $filters, int $page, User $actor, int $perPage = 50): LengthAwarePaginator
    {
        $run = $this->getRun($school, $runId, $actor);

        return $this->context->withSchool($school, fn () => FeeAssessmentRunItem::query()
            ->where('fee_assessment_run_id', $run->id)
            ->when(isset($filters['preview_result']), fn ($q) => $q->where('preview_result', $filters['preview_result']))
            ->when(isset($filters['execution_status']), fn ($q) => $q->where('execution_status', $filters['execution_status']))
            ->orderBy('student_id')
            ->orderBy('fee_structure_line_id')
            ->paginate($perPage, ['*'], 'page', $page));
    }
}
