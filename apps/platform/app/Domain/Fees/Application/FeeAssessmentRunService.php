<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunIllegalStateException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunItemNotExcludableException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunItemNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunOpenConflictException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunStalePreviewException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeAssessmentRunException;
use App\Domain\Fees\Domain\FeeCode;
use App\Domain\Fees\Events\FeeAssessmentRunCompleted;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Students\Application\FeeTargetEnrollment;
use App\Domain\Students\Application\StudentEnrollmentFeeTargetReadService;
use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FEE.2 (ADR 0062 §9-§13): the staff-facing lifecycle of an assessment run
 * -- create, preview, exclude, execute, resume, cancel -- plus the trusted
 * finalize/pause steps the job calls. Charge creation itself happens only
 * in `FeeAssessmentItemExecutor`.
 *
 * Authorization boundary: every staff method checks
 * `finance.fee_assessments.run`. Reads live in `FeeAssessmentRunReadService`.
 *
 * Preview eligibility (owner decision D1, no proration ever):
 * - targets come from Students' `StudentEnrollmentFeeTargetReadService`:
 *   the structure's year and grade, and its campus (override) or every
 *   campus without its own active override (School default);
 * - per Student x line, the enrollment that overlaps the instalment's
 *   billing period (latest start) is used; one that only starts after the
 *   period ended is `period_before_enrollment`; only cancelled ones are
 *   `enrollment_cancelled`;
 * - then `student_inactive`, `optional_not_selected` (no active explicit
 *   selection), `head_inactive`, `account_invalid`, `already_assessed`
 *   (advisory -- the database key decides at execution), a carried-over
 *   `staff_excluded`, else `ready` at the instalment's full amount.
 */
class FeeAssessmentRunService
{
    use AuthorizesCapability;

    private const CAPABILITY = 'finance.fee_assessments.run';

    public function __construct(
        private readonly StudentEnrollmentFeeTargetReadService $targets,
        private readonly FeeStructureResolver $resolver,
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(School $school, string $feeStructureId, string $billingPeriodKey, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);
        $key = FeeCode::normalize($billingPeriodKey);

        return $this->context->withSchool($school, function () use ($school, $feeStructureId, $key, $actor) {
            return DB::transaction(function () use ($school, $feeStructureId, $key, $actor) {
                $structure = FeeStructure::query()->where('school_id', $school->id)->sharedLock()->find($feeStructureId);

                if ($structure === null || ! $structure->isActive()) {
                    throw new InvalidFeeAssessmentRunException('fee_structure_id', 'Only an active fee structure can be assessed.');
                }

                if ($this->installmentsFor($structure, $key)->isEmpty()) {
                    throw new InvalidFeeAssessmentRunException('billing_period_key', "Billing period '{$key}' does not exist on this fee structure.");
                }

                $run = new FeeAssessmentRun;
                $run->forceFill([
                    'school_id' => $school->id,
                    'fee_structure_id' => $structure->id,
                    'billing_period_key' => $key,
                    'status' => FeeAssessmentRun::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                ]);

                try {
                    $run->save();
                } catch (UniqueConstraintViolationException $e) {
                    if (! str_contains($e->getMessage(), 'fee_assessment_runs_one_open_per_period')) {
                        throw $e;
                    }

                    throw new FeeAssessmentRunOpenConflictException($key);
                }

                $this->audit->school($school, 'fee_assessment_run.created', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'feeStructureId' => $structure->id,
                    'billingPeriodKey' => $key,
                ]);

                return $run->refresh();
            });
        });
    }

    public function preview(School $school, string $runId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId, $actor) {
            return DB::transaction(function () use ($school, $runId, $actor) {
                $run = $this->lockRun($school, $runId);

                if (! in_array($run->status, [FeeAssessmentRun::STATUS_DRAFT, FeeAssessmentRun::STATUS_PREVIEWED], true)) {
                    throw new FeeAssessmentRunIllegalStateException($run->status, 'preview');
                }

                $structure = FeeStructure::query()->where('school_id', $school->id)->sharedLock()->findOrFail($run->fee_structure_id);
                if (! $structure->isActive()) {
                    throw new InvalidFeeAssessmentRunException('fee_structure_id', 'The fee structure is no longer active; cancel this run.');
                }

                $carried = FeeAssessmentRunItem::query()
                    ->where('fee_assessment_run_id', $run->id)
                    ->where('reason', FeeAssessmentRunItem::REASON_STAFF_EXCLUDED)
                    ->get()
                    ->keyBy(fn (FeeAssessmentRunItem $i) => $i->student_id.'|'.$i->fee_structure_line_id);

                $rows = $this->computeItems($school, $run, $structure, $carried);

                FeeAssessmentRunItem::query()->where('fee_assessment_run_id', $run->id)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('fee_assessment_run_items')->insert($chunk);
                }

                $totals = $this->previewTotals($run);

                $run->forceFill([
                    ...$totals,
                    'status' => FeeAssessmentRun::STATUS_PREVIEWED,
                    'previewed_configuration_version' => $run->configuration_version,
                    'previewed_at' => now(),
                    'previewed_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'fee_assessment_run.previewed', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'configurationVersion' => $run->configuration_version,
                    'readyCount' => $totals['ready_count'],
                    'excludedCount' => $totals['excluded_count'],
                    'blockedCount' => $totals['blocked_count'],
                    'alreadyAssessedCount' => $totals['already_assessed_count'],
                ]);

                return $run->refresh();
            });
        });
    }

    /**
     * Owner decision D1: an explicit staff exclusion of one READY item. It
     * is run provenance only (item reason `staff_excluded`, actor, time,
     * audit) -- never a structure change and never a reduced amount -- and
     * it returns the run to `draft`, so a fresh preview is required.
     */
    public function excludeItem(School $school, string $runId, string $itemId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId, $itemId, $actor) {
            return DB::transaction(function () use ($school, $runId, $itemId, $actor) {
                $run = $this->lockRun($school, $runId);

                if (! in_array($run->status, [FeeAssessmentRun::STATUS_DRAFT, FeeAssessmentRun::STATUS_PREVIEWED], true)) {
                    throw new FeeAssessmentRunIllegalStateException($run->status, 'exclude an item');
                }

                $item = FeeAssessmentRunItem::query()->where('fee_assessment_run_id', $run->id)->lockForUpdate()->find($itemId);
                if ($item === null) {
                    throw new FeeAssessmentRunItemNotFoundException($itemId);
                }

                if ($item->preview_result !== FeeAssessmentRunItem::RESULT_READY) {
                    throw new FeeAssessmentRunItemNotExcludableException($item->id);
                }

                $item->forceFill([
                    'preview_result' => FeeAssessmentRunItem::RESULT_EXCLUDED,
                    'reason' => FeeAssessmentRunItem::REASON_STAFF_EXCLUDED,
                    'excluded_by_user_id' => $actor->id,
                    'excluded_at' => now(),
                ])->save();

                $run->forceFill([
                    'status' => FeeAssessmentRun::STATUS_DRAFT,
                    'configuration_version' => $run->configuration_version + 1,
                ])->save();

                $this->audit->school($school, 'fee_assessment_run.item_excluded', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'itemId' => $item->id,
                    'studentId' => $item->student_id,
                    'feeStructureLineId' => $item->fee_structure_line_id,
                ]);

                return $run->refresh();
            });
        });
    }

    /**
     * Claims a previewed run (`previewed -> executing` only when the preview
     * is current), marks its ready items pending, and queues the job. Any
     * change since the preview is a typed stale-preview conflict.
     */
    public function execute(School $school, string $runId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId, $actor) {
            $run = DB::transaction(function () use ($school, $runId, $actor) {
                $run = $this->lockRun($school, $runId);

                if ($run->status === FeeAssessmentRun::STATUS_DRAFT) {
                    throw new FeeAssessmentRunStalePreviewException($run->id);
                }

                if ($run->status !== FeeAssessmentRun::STATUS_PREVIEWED) {
                    throw new FeeAssessmentRunIllegalStateException($run->status, 'execute');
                }

                $claimed = FeeAssessmentRun::query()
                    ->whereKey($run->id)
                    ->where('status', FeeAssessmentRun::STATUS_PREVIEWED)
                    ->whereColumn('configuration_version', 'previewed_configuration_version')
                    ->update([
                        'status' => FeeAssessmentRun::STATUS_EXECUTING,
                        'execution_started_at' => now(),
                        'executed_by_user_id' => $actor->id,
                        'updated_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    throw new FeeAssessmentRunStalePreviewException($run->id);
                }

                $pending = FeeAssessmentRunItem::query()
                    ->where('fee_assessment_run_id', $run->id)
                    ->where('preview_result', FeeAssessmentRunItem::RESULT_READY)
                    ->update(['execution_status' => FeeAssessmentRunItem::EXEC_PENDING, 'updated_at' => now()]);

                $run->refresh();

                $this->audit->school($school, 'fee_assessment_run.execution_started', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'pendingCount' => $pending,
                ]);

                return $run;
            });

            ExecuteFeeAssessmentRunJob::dispatch($run->id);

            // A synchronously-run job clears the tenant context when it
            // finishes; re-enter it to read the run back.
            return $this->context->withSchool($school, fn () => $run->refresh());
        });
    }

    /** Re-queues an executing run (after a crash, timeout or School pause). Only pending items are ever picked up. */
    public function resume(School $school, string $runId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId) {
            $run = FeeAssessmentRun::query()->where('school_id', $school->id)->find($runId)
                ?? throw new FeeAssessmentRunNotFoundException($runId);

            if ($run->status !== FeeAssessmentRun::STATUS_EXECUTING) {
                throw new FeeAssessmentRunIllegalStateException($run->status, 'resume');
            }

            ExecuteFeeAssessmentRunJob::dispatch($run->id);

            return $this->context->withSchool($school, fn () => $run->refresh());
        });
    }

    public function cancel(School $school, string $runId, User $actor): FeeAssessmentRun
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, function () use ($school, $runId, $actor) {
            return DB::transaction(function () use ($school, $runId, $actor) {
                $run = $this->lockRun($school, $runId);

                if (! in_array($run->status, [FeeAssessmentRun::STATUS_DRAFT, FeeAssessmentRun::STATUS_PREVIEWED], true)) {
                    throw new FeeAssessmentRunIllegalStateException($run->status, 'cancel');
                }

                $run->forceFill([
                    'status' => FeeAssessmentRun::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'fee_assessment_run.cancelled', actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                ]);

                return $run->refresh();
            });
        });
    }

    /**
     * Trusted (called by the job): once no item is pending, re-counts under
     * the run lock and moves `executing -> completed | completed_with_errors`,
     * with one audit event and one outbox event. Idempotent: a second call
     * on a terminal run, or with pending items left, changes nothing.
     */
    public function finalize(School $school, string $runId): ?FeeAssessmentRun
    {
        return $this->context->withSchool($school, function () use ($school, $runId) {
            return DB::transaction(function () use ($school, $runId) {
                $run = $this->lockRun($school, $runId);

                if ($run->status !== FeeAssessmentRun::STATUS_EXECUTING) {
                    return null;
                }

                $counts = FeeAssessmentRunItem::query()
                    ->where('fee_assessment_run_id', $run->id)
                    ->whereNotNull('execution_status')
                    ->selectRaw('execution_status, count(*) as n, coalesce(sum(amount), 0) as total')
                    ->groupBy('execution_status')
                    ->get()
                    ->keyBy('execution_status');

                if (isset($counts[FeeAssessmentRunItem::EXEC_PENDING])) {
                    return null;
                }

                $failed = (int) ($counts[FeeAssessmentRunItem::EXEC_FAILED]->n ?? 0);
                $status = $failed > 0 ? FeeAssessmentRun::STATUS_COMPLETED_WITH_ERRORS : FeeAssessmentRun::STATUS_COMPLETED;

                $run->forceFill([
                    'status' => $status,
                    'completed_at' => now(),
                    'succeeded_count' => (int) ($counts[FeeAssessmentRunItem::EXEC_SUCCEEDED]->n ?? 0),
                    'skipped_count' => (int) ($counts[FeeAssessmentRunItem::EXEC_SKIPPED]->n ?? 0),
                    'failed_count' => $failed,
                    'assessed_amount' => (string) ($counts[FeeAssessmentRunItem::EXEC_SUCCEEDED]->total ?? '0.00'),
                ])->save();

                $actor = $run->executed_by_user_id === null ? null : User::query()->find($run->executed_by_user_id);

                $this->audit->school($school, 'fee_assessment_run.'.$status, actor: $actor, subject: $run, metadata: [
                    'runId' => $run->id,
                    'succeededCount' => $run->succeeded_count,
                    'skippedCount' => $run->skipped_count,
                    'failedCount' => $run->failed_count,
                ]);

                event(new FeeAssessmentRunCompleted(
                    $school->id, $run->id, $run->fee_structure_id, $run->billing_period_key, $status,
                    $run->succeeded_count, $run->skipped_count, $run->failed_count,
                ));

                return $run->refresh();
            });
        });
    }

    /** Trusted (called by the job): a suspended School paused an executing run. Nothing else changes. */
    public function recordPaused(School $school, string $runId): void
    {
        $this->context->withSchool($school, function () use ($school, $runId) {
            $run = FeeAssessmentRun::query()->where('school_id', $school->id)->find($runId);

            if ($run !== null && $run->status === FeeAssessmentRun::STATUS_EXECUTING) {
                $this->audit->school($school, 'fee_assessment_run.paused', subject: $run, metadata: [
                    'runId' => $run->id,
                    'reason' => 'school_not_operational',
                ]);
            }
        });
    }

    // --- internals ------------------------------------------------------------

    private function lockRun(School $school, string $runId): FeeAssessmentRun
    {
        $run = FeeAssessmentRun::query()->where('school_id', $school->id)->lockForUpdate()->find($runId);

        if ($run === null) {
            throw new FeeAssessmentRunNotFoundException($runId);
        }

        return $run;
    }

    /** @return Collection<int, FeeStructureInstallment> */
    private function installmentsFor(FeeStructure $structure, string $key): Collection
    {
        return FeeStructureInstallment::query()
            ->whereIn('fee_structure_line_id', FeeStructureLine::query()->where('fee_structure_id', $structure->id)->select('id'))
            ->where('billing_period_key', $key)
            ->get();
    }

    /**
     * @param  Collection<string, FeeAssessmentRunItem>  $carried  staff exclusions keyed "studentId|lineId"
     * @return list<array<string, mixed>>
     */
    private function computeItems(School $school, FeeAssessmentRun $run, FeeStructure $structure, Collection $carried): array
    {
        $installments = $this->installmentsFor($structure, $run->billing_period_key);
        $lines = FeeStructureLine::query()->whereIn('id', $installments->pluck('fee_structure_line_id'))->get()->keyBy('id');
        $heads = FeeHead::query()->whereIn('id', $lines->pluck('fee_head_id'))->get()->keyBy('id');
        $assets = $this->ledger->activeAccountsOfType($school, 'asset')->map(fn (LedgerAccountSummary $a) => $a->ledgerAccountId)->flip();
        $income = $this->ledger->activeAccountsOfType($school, 'income')->map(fn (LedgerAccountSummary $a) => $a->ledgerAccountId)->flip();

        $earliestStart = CarbonImmutable::parse($installments->min(fn ($i) => $i->period_starts_on->toDateString()));
        $excludedCampuses = $structure->campus_id === null
            ? $this->resolver->overrideCampusIds($school, $structure->academic_year_id, $structure->grade_level_id)
            : [];

        $byStudent = $this->targets
            ->candidatesForGrade($school, $structure->academic_year_id, $structure->grade_level_id, $structure->campus_id, $excludedCampuses, $earliestStart)
            ->groupBy(fn (FeeTargetEnrollment $e) => $e->studentId);

        $studentIds = $byStudent->keys()->all();
        $headIds = $heads->keys()->all();

        $selected = FeeOptionalSelection::query()
            ->where('academic_year_id', $structure->academic_year_id)
            ->where('status', FeeOptionalSelection::STATUS_ACTIVE)
            ->whereIn('student_id', $studentIds)
            ->whereIn('fee_head_id', $headIds)
            ->get()
            ->mapWithKeys(fn (FeeOptionalSelection $s) => [$s->student_id.'|'.$s->fee_head_id => true]);

        $assessed = FeeAssessment::query()
            ->where('academic_year_id', $structure->academic_year_id)
            ->where('billing_period_key', $run->billing_period_key)
            ->whereNull('voided_at')
            ->whereIn('student_id', $studentIds)
            ->whereIn('fee_head_id', $headIds)
            ->get()
            ->mapWithKeys(fn (FeeAssessment $a) => [$a->student_id.'|'.$a->fee_head_id => true]);

        $now = now();
        $rows = [];

        foreach ($byStudent as $studentId => $enrollments) {
            foreach ($installments as $installment) {
                $line = $lines[$installment->fee_structure_line_id];
                $head = $heads[$line->fee_head_id];
                $from = CarbonImmutable::parse($installment->period_starts_on);
                $to = CarbonImmutable::parse($installment->period_ends_on);

                [$enrollment, $result, $reason] = $this->classifyEnrollment($enrollments, $from, $to);

                if ($enrollment === null) {
                    continue; // every enrollment ended before this line's period started
                }

                if ($result === null && ! $enrollment->studentIsActive) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_STUDENT_INACTIVE];
                }
                if ($result === null && $line->is_optional && ! isset($selected[$studentId.'|'.$head->id])) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_OPTIONAL_NOT_SELECTED];
                }
                if ($result === null && ! $head->isActive()) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_BLOCKED, FeeAssessmentRunItem::REASON_HEAD_INACTIVE];
                }
                if ($result === null && (! isset($assets[$head->receivable_ledger_account_id]) || ! isset($income[$head->revenue_ledger_account_id]))) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_BLOCKED, FeeAssessmentRunItem::REASON_ACCOUNT_INVALID];
                }
                if ($result === null && isset($assessed[$studentId.'|'.$head->id])) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_ALREADY_ASSESSED, null];
                }

                $exclusion = $result === null ? $carried->get($studentId.'|'.$line->id) : null;
                if ($exclusion !== null) {
                    [$result, $reason] = [FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_STAFF_EXCLUDED];
                }

                $rows[] = [
                    'id' => (string) new UuidV7,
                    'school_id' => $school->id,
                    'fee_assessment_run_id' => $run->id,
                    'student_id' => $studentId,
                    'student_enrollment_id' => $enrollment->enrollmentId,
                    'campus_id' => $enrollment->campusId,
                    'grade_level_id' => $enrollment->gradeLevelId,
                    'enrollment_starts_on' => $enrollment->startsOn->toDateString(),
                    'fee_structure_line_id' => $line->id,
                    'fee_structure_installment_id' => $installment->id,
                    'fee_head_id' => $head->id,
                    'billing_period_key' => $run->billing_period_key,
                    'amount' => (string) $installment->amount,
                    'currency' => 'INR',
                    'preview_result' => $result ?? FeeAssessmentRunItem::RESULT_READY,
                    'reason' => $reason,
                    'excluded_by_user_id' => $exclusion?->excluded_by_user_id,
                    'excluded_at' => $exclusion?->excluded_at,
                    'execution_status' => null,
                    'failure_reason' => null,
                    'fee_assessment_id' => null,
                    'executed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * Picks the Student's enrollment for one billing period (D1): the
     * non-cancelled enrollment overlapping the period with the latest start;
     * otherwise one that starts only after the period ended
     * (`period_before_enrollment`, never a backdated charge); otherwise a
     * cancelled one that overlaps (`enrollment_cancelled`).
     *
     * @param  Collection<int, FeeTargetEnrollment>  $enrollments
     * @return array{0: ?FeeTargetEnrollment, 1: ?string, 2: ?string}
     */
    private function classifyEnrollment(Collection $enrollments, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $live = $enrollments->reject(fn (FeeTargetEnrollment $e) => $e->isCancelled());

        $overlapping = $live->filter(fn (FeeTargetEnrollment $e) => $e->overlaps($from, $to))
            ->sortByDesc(fn (FeeTargetEnrollment $e) => $e->startsOn->toDateString().'|'.$e->enrollmentId);
        if ($overlapping->isNotEmpty()) {
            return [$overlapping->first(), null, null];
        }

        $later = $live->filter(fn (FeeTargetEnrollment $e) => $e->startsOn->gt($to))->sortBy(fn (FeeTargetEnrollment $e) => $e->startsOn->toDateString());
        if ($later->isNotEmpty()) {
            return [$later->first(), FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_PERIOD_BEFORE_ENROLLMENT];
        }

        $cancelled = $enrollments->filter(fn (FeeTargetEnrollment $e) => $e->isCancelled() && $e->overlaps($from, $to));
        if ($cancelled->isNotEmpty()) {
            return [$cancelled->first(), FeeAssessmentRunItem::RESULT_EXCLUDED, FeeAssessmentRunItem::REASON_ENROLLMENT_CANCELLED];
        }

        return [null, null, null];
    }

    /** @return array{ready_count: int, ready_amount: string, excluded_count: int, blocked_count: int, already_assessed_count: int} */
    private function previewTotals(FeeAssessmentRun $run): array
    {
        $rows = FeeAssessmentRunItem::query()
            ->where('fee_assessment_run_id', $run->id)
            ->selectRaw('preview_result, count(*) as n, coalesce(sum(amount), 0) as total')
            ->groupBy('preview_result')
            ->get()
            ->keyBy('preview_result');

        return [
            'ready_count' => (int) ($rows[FeeAssessmentRunItem::RESULT_READY]->n ?? 0),
            'ready_amount' => (string) ($rows[FeeAssessmentRunItem::RESULT_READY]->total ?? '0.00'),
            'excluded_count' => (int) ($rows[FeeAssessmentRunItem::RESULT_EXCLUDED]->n ?? 0),
            'blocked_count' => (int) ($rows[FeeAssessmentRunItem::RESULT_BLOCKED]->n ?? 0),
            'already_assessed_count' => (int) ($rows[FeeAssessmentRunItem::RESULT_ALREADY_ASSESSED]->n ?? 0),
        ];
    }
}
