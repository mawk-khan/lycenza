<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1B.7E: the canonical read layer for the rollover domain --
 * mirrors StudentEnrollmentReadService's exact shape (plain Eloquent
 * models/paginators, no DTOs/Resources, authorization-neutral, relies
 * entirely on the ambient SchoolScope/RLS already active on every
 * rollover table). A future controller must call
 * `Gate::authorize`/`authorizeCapability` with `enrollments.view`
 * AND `enrollments.rollovers.view` before ever reaching this class.
 *
 * Never mutates state. `executionSummary()` is a plain read-only
 * aggregate COUNT query -- it deliberately does NOT call
 * EnrollmentRolloverExecutionService's private `summarize()` (that
 * service owns the bulk-execution ALGORITHM, not this trivial GROUP
 * BY), matching the same "read layer doesn't reach into the write
 * service's internals" boundary StudentEnrollmentReadService already
 * establishes against StudentEnrollmentService.
 */
class EnrollmentRolloverReadService
{
    /**
     * @param  array{source_academic_year_id?: string, target_academic_year_id?: string, status?: string}  $filters
     */
    public function directory(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = EnrollmentRolloverPlan::query()
            ->with(['sourceAcademicYear', 'targetAcademicYear', 'createdBy:id,name'])
            ->orderByDesc('created_at');

        if (isset($filters['source_academic_year_id'])) {
            $query->where('source_academic_year_id', $filters['source_academic_year_id']);
        }
        if (isset($filters['target_academic_year_id'])) {
            $query->where('target_academic_year_id', $filters['target_academic_year_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function detail(string $planId): ?EnrollmentRolloverPlan
    {
        return EnrollmentRolloverPlan::query()
            ->with([
                'sourceAcademicYear', 'targetAcademicYear', 'createdBy:id,name',
                'mappings.sourceGradeLevel', 'mappings.sourceSection', 'mappings.targetGradeLevel', 'mappings.targetSection',
            ])
            ->find($planId);
    }

    /**
     * @param  array{validation_result?: string, execution_status?: string, decision?: string}  $filters
     */
    public function items(EnrollmentRolloverPlan $plan, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->with(['student:id,school_id,student_number,first_name,middle_name,last_name', 'sourceEnrollment.section', 'targetSection'])
            ->orderBy('id');

        if (isset($filters['validation_result'])) {
            $query->where('validation_result', $filters['validation_result']);
        }
        if (isset($filters['execution_status'])) {
            $query->where('execution_status', $filters['execution_status']);
        }
        if (isset($filters['decision'])) {
            $query->where('decision', $filters['decision']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @return array{total: int, succeeded: int, reconciled: int, skipped: int, failed: int, pending: int}
     */
    public function executionSummary(EnrollmentRolloverPlan $plan): array
    {
        $counts = DB::table('enrollment_rollover_items')
            ->where('plan_id', $plan->id)
            ->selectRaw('execution_status, count(*) as c')
            ->groupBy('execution_status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->execution_status ?? 'pending' => (int) $row->c]);

        $total = (int) $counts->sum();
        $succeeded = $counts->get('succeeded', 0);
        $reconciled = $counts->get('reconciled', 0);
        $skipped = $counts->get('skipped', 0);
        $failed = $counts->get('failed', 0);

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'reconciled' => $reconciled,
            'skipped' => $skipped,
            'failed' => $failed,
            'pending' => $total - $succeeded - $reconciled - $skipped - $failed,
        ];
    }
}
