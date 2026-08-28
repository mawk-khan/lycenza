<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
                'subjectMappings.sourceSubjectOffering.subject', 'subjectMappings.sourceSubjectOffering.gradeLevel', 'subjectMappings.sourceSubjectOffering.campus',
                'subjectMappings.targetSubjectOffering.subject', 'subjectMappings.targetSubjectOffering.gradeLevel', 'subjectMappings.targetSubjectOffering.campus', 'subjectMappings.targetSubjectOffering.electiveGroup',
            ])
            ->find($planId);
    }

    /**
     * Phase 1G.4: the bounded, read-only discovery adapter behind the
     * Plan workspace's "needs configuration" list -- without this, an
     * operator has no way to discover WHICH source elective
     * SubjectOfferings are missing a mapping row before running dry-run
     * (this checkpoint's brief, section 18/19). Bounded by the number
     * of DISTINCT elective SubjectOfferings this Plan's Students
     * actually participate in (School reference-data cardinality, never
     * Student-row cardinality) -- never writes a mapping row, never
     * infers a desired target. Deliberately inclusive of legacy
     * NULL-anchor participation regardless of anchor ambiguity -- this
     * is a "what needs a decision" list, not a re-implementation of
     * SubjectRolloverResolution's strict per-Item candidate proof; an
     * ambiguous legacy row still surfaces its source Offering here (and
     * still gets a proper `legacy_source_anchor_ambiguous` dry-run
     * reason once validated).
     *
     * @return Collection<int, SubjectOffering>
     */
    public function unmappedSourceSubjectOfferings(EnrollmentRolloverPlan $plan): Collection
    {
        $items = EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->get(['id', 'student_id', 'source_enrollment_id']);

        if ($items->isEmpty()) {
            return collect();
        }

        $sourceEnrollmentIds = $items->pluck('source_enrollment_id')->unique()->all();
        $studentIds = $items->pluck('student_id')->unique()->all();

        $offeringIds = StudentSubjectEnrollment::query()
            ->where('status', 'active')
            ->where(function ($query) use ($sourceEnrollmentIds, $studentIds, $plan) {
                $query->whereIn('student_enrollment_id', $sourceEnrollmentIds)
                    ->orWhere(function ($query) use ($studentIds, $plan) {
                        $query->whereNull('student_enrollment_id')
                            ->whereIn('student_id', $studentIds)
                            ->where('academic_year_id', $plan->source_academic_year_id);
                    });
            })
            ->pluck('subject_offering_id')
            ->unique();

        $mappedIds = $plan->subjectMappings()->pluck('source_subject_offering_id');
        $unmappedIds = $offeringIds->diff($mappedIds)->values();

        if ($unmappedIds->isEmpty()) {
            return collect();
        }

        return SubjectOffering::query()
            ->whereIn('id', $unmappedIds)
            ->where('is_required', false)
            ->with(['subject', 'gradeLevel', 'campus'])
            ->orderBy('sequence')
            ->get();
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

    /**
     * Phase 1B.7F: the LAST persisted dry-run classification per Item
     * (`validation_result`), grouped -- distinct from `executionSummary()`
     * above (a different dimension: what execution actually DID, vs
     * what validation PROPOSED). Needed by the "high-risk execution
     * review" panel (this checkpoint's brief, section 42) to show
     * Ready/Excluded/Already-enrolled/Review/Blocked counts BEFORE
     * Start is offered -- `EnrollmentRolloverDryRunService::run()`'s own
     * return value only exists transiently, right after a validate()
     * call; this recomputes it fresh from persisted Items so the
     * Plan-detail page reflects the LAST validation result even after a
     * fresh page load. A plain read-only aggregate COUNT query, not a
     * re-evaluation -- never re-runs dry-run's own classification
     * logic.
     *
     * @return array{total: int, ready: int, excluded: int, already_enrolled: int, review: int, blocked: int, unvalidated: int}
     */
    public function validationSummary(EnrollmentRolloverPlan $plan): array
    {
        $counts = DB::table('enrollment_rollover_items')
            ->where('plan_id', $plan->id)
            ->selectRaw('validation_result, count(*) as c')
            ->groupBy('validation_result')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->validation_result ?? 'unvalidated' => (int) $row->c]);

        $total = (int) $counts->sum();

        return [
            'total' => $total,
            'ready' => $counts->get('ready', 0),
            'excluded' => $counts->get('excluded', 0),
            'already_enrolled' => $counts->get('already_enrolled', 0),
            'review' => $counts->get('review', 0),
            'blocked' => $counts->get('blocked', 0),
            'unvalidated' => $counts->get('unvalidated', 0),
        ];
    }
}
