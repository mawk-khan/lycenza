<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\RolloverPlanAlreadyExecutingException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotExecutionReadyException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotResumableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1B.7D: the PLAN-LEVEL bulk/resumable rollover execution
 * orchestrator -- see docs/modules/STUDENT-ENROLLMENT.md ("Bulk &
 * Resumable Rollover Execution (Phase 1B.7D)") for the accepted
 * design. Owns claiming a validated Plan for execution, processing its
 * Items in deterministic bounded batches, and finalizing the Plan once
 * every Item reaches a terminal-accepted outcome -- it NEVER duplicates
 * one-Item promotion logic itself: every actual academic mutation is
 * delegated to `EnrollmentRolloverItemExecutionService::execute()`,
 * exactly once per Item, per this class's own docblock invariant.
 *
 * Never runs the whole Plan inside one transaction. Only two short,
 * Plan-row-locked transactions exist here (`claim()`, `finalize()`);
 * every Student's own promotion continues to use ITS OWN transaction,
 * owned entirely by `EnrollmentRolloverItemExecutionService::execute()`
 * -- this class never opens a `DB::transaction()` around more than one
 * Item.
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase.
 */
class EnrollmentRolloverExecutionService
{
    private const DEFAULT_BATCH_SIZE = 100;

    private const TERMINAL_ACCEPTED_EXECUTION_STATUSES = ['succeeded', 'reconciled', 'skipped'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly EnrollmentRolloverItemExecutionService $itemExecution,
    ) {}

    /**
     * Claims a `validated` Plan for execution and processes it until
     * either every Item reaches a terminal-accepted outcome (Plan
     * finalizes) or execution is stopped by drift/interruption (Plan
     * stays `executing`, or reverts to `draft` if the per-Item
     * primitive invalidated it -- see `processUntilStoppedOrDone()`).
     *
     * Refuses a Plan that is not currently `validated` for its present
     * configuration (`RolloverPlanNotExecutionReadyException`) or that
     * is ALREADY `executing` (`RolloverPlanAlreadyExecutingException`
     * -- use `resume()` instead; a duplicate/concurrent `start()` call
     * never becomes a second active processor for the same Plan).
     *
     * @param  callable(string): bool|null  $afterEachItem  Invoked with the just-processed
     *                                                      Item's id after every Item; returning true stops
     *                                                      processing immediately. Sanctioned callers: Phase
     *                                                      1B.7D's own tests deterministically simulate a
     *                                                      crashed/interrupted process without any timing/
     *                                                      sleep-based test; Phase 1B.7E's JSON API controller
     *                                                      and Phase 1B.7F's Inertia web controller both bound
     *                                                      one synchronous HTTP request to the SAME server-owned
     *                                                      Item cap (App\Support\Rollover\BoundsRolloverExecutionRequest),
     *                                                      since no queue exists yet -- never exposed to a
     *                                                      caller as a parameter itself.
     * @return array{total: int, succeeded: int, reconciled: int, skipped: int, failed: int, pending: int, planStatus: string}
     */
    public function start(EnrollmentRolloverPlan $plan, ?User $actor = null, int $batchSize = self::DEFAULT_BATCH_SIZE, ?callable $afterEachItem = null): array
    {
        $claimed = $this->claim($plan, $actor);

        return $this->processUntilStoppedOrDone($claimed, $actor, $batchSize, $afterEachItem);
    }

    /**
     * Continues an ALREADY-`executing` Plan -- no claim/transition step
     * (it is already claimed), just re-verifies it is still genuinely
     * executing before resuming the identical bounded-batch loop
     * `start()` uses. Refuses a Plan that is not currently `executing`
     * (`RolloverPlanNotResumableException`) -- including one that has
     * already finalized, which is what makes calling `resume()` again
     * after completion a clean no-op rejection rather than a silent
     * re-run (this checkpoint's brief, section 68).
     *
     * @param  callable(string): bool|null  $afterEachItem  See `start()`.
     * @return array{total: int, succeeded: int, reconciled: int, skipped: int, failed: int, pending: int, planStatus: string}
     */
    public function resume(EnrollmentRolloverPlan $plan, ?User $actor = null, int $batchSize = self::DEFAULT_BATCH_SIZE, ?callable $afterEachItem = null): array
    {
        $fresh = $this->reloadPlan($plan);

        if ($fresh->status !== 'executing') {
            throw new RolloverPlanNotResumableException;
        }

        $remaining = $this->countPending($fresh);

        $this->context->withSchool($fresh->school, fn () => $this->audit->school($fresh->school, 'enrollment_rollover.execution_resumed', actor: $actor, subject: $fresh, metadata: [
            'planId' => $fresh->id,
            'configurationVersion' => $fresh->configuration_version,
            'remaining' => $remaining,
        ]));

        return $this->processUntilStoppedOrDone($fresh, $actor, $batchSize, $afterEachItem);
    }

    /**
     * Short, Plan-row-locked claim transaction -- the SAME
     * "lock, verify, conditional UPDATE, check affected rows" double
     * guard `StudentEnrollmentService`'s own lifecycle transitions
     * already establish. The row lock alone already serializes two
     * concurrent claim attempts (the second `SELECT ... FOR UPDATE`
     * blocks until the first commits, then observes 'executing'); the
     * conditional `WHERE status = 'validated'` UPDATE is kept as
     * defense in depth, matching that same precedent, not because the
     * lock alone is insufficient.
     */
    private function claim(EnrollmentRolloverPlan $plan, ?User $actor): EnrollmentRolloverPlan
    {
        return $this->context->withSchool($plan->school, function () use ($plan, $actor) {
            return DB::transaction(function () use ($plan, $actor) {
                $locked = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'executing') {
                    throw new RolloverPlanAlreadyExecutingException;
                }

                if ($locked->status !== 'validated' || $locked->validated_configuration_version !== $locked->configuration_version) {
                    throw new RolloverPlanNotExecutionReadyException;
                }

                $affected = EnrollmentRolloverPlan::query()
                    ->whereKey($locked->id)
                    ->where('status', 'validated')
                    ->update([
                        'status' => 'executing',
                        // Never rewritten on a later start/resume -- this
                        // checkpoint's brief, section 12. Preserved
                        // exactly once, the first time a Plan is ever
                        // claimed.
                        'execution_started_at' => $locked->execution_started_at ?? now(),
                    ]);

                if ($affected === 0) {
                    throw new RolloverPlanAlreadyExecutingException;
                }

                $totalItems = EnrollmentRolloverItem::query()->where('plan_id', $locked->id)->count();

                $this->audit->school($locked->school, 'enrollment_rollover.execution_started', actor: $actor, subject: $locked, metadata: [
                    'planId' => $locked->id,
                    'configurationVersion' => $locked->configuration_version,
                    'totalItems' => $totalItems,
                ]);

                return $locked->refresh();
            });
        });
    }

    /**
     * The one shared processing loop `start()`/`resume()` both funnel
     * into. Re-verifies the Plan is still genuinely executable BEFORE
     * every batch AND after every single Item (this checkpoint's
     * brief, section 21 -- "STOP immediately", not merely "before the
     * next batch") -- a per-Item primitive call that invalidates the
     * Plan (external drift: source changed, target Section changed, a
     * conflicting target Enrollment, a Roll Number race) is detected
     * on the VERY NEXT check, before any later Item is ever attempted.
     * Never re-runs dry-run itself, never overwrites an invalidated
     * Plan's `draft` status with `completed`/`completed_with_errors`.
     *
     * Item selection is offset-free by construction (this checkpoint's
     * brief, section 36): the WHERE clause itself
     * (`execution_status NOT IN (terminal-accepted)`) naturally shrinks
     * as Items are processed, so repeating the identical bounded query
     * with no offset always returns the next genuinely-still-pending
     * batch -- a processed Item can never cause another to be skipped.
     */
    private function processUntilStoppedOrDone(EnrollmentRolloverPlan $plan, ?User $actor, int $batchSize, ?callable $afterEachItem): array
    {
        $current = $plan;

        while (true) {
            if (! $this->isExecutionReady($current)) {
                return $this->summarize($current);
            }

            $batch = $this->nextPendingItemIds($current, $batchSize);

            if ($batch->isEmpty()) {
                return $this->finalize($current, $actor);
            }

            foreach ($batch as $itemId) {
                $item = $this->context->withSchool($current->school, fn () => EnrollmentRolloverItem::query()->whereKey($itemId)->firstOrFail());

                $this->itemExecution->execute($item, $actor);

                if ($afterEachItem !== null && $afterEachItem($itemId) === true) {
                    return $this->summarize($this->reloadPlan($current));
                }

                $current = $this->reloadPlan($current);

                if (! $this->isExecutionReady($current)) {
                    return $this->summarize($current);
                }
            }
        }
    }

    /**
     * Short, Plan-row-locked finalization transaction. Re-checks
     * status/configuration-version AND re-counts pending Items under
     * the lock -- never trusts the caller's in-memory belief that
     * processing is complete (this checkpoint's brief, section 70). If
     * anything has changed since the loop's last check, this returns
     * the Plan's CURRENT summary without finalizing -- a caller can
     * simply call `resume()` again.
     */
    private function finalize(EnrollmentRolloverPlan $plan, ?User $actor): array
    {
        return $this->context->withSchool($plan->school, function () use ($plan, $actor) {
            return DB::transaction(function () use ($plan, $actor) {
                $locked = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                if (! $this->isExecutionReady($locked) || $this->countPending($locked) > 0) {
                    return $this->summarize($locked);
                }

                $summary = $this->summarize($locked);

                // Structurally near-unreachable today (this checkpoint's
                // brief, section 26): every path that marks an Item
                // 'failed' (EnrollmentRolloverItemExecutionService::invalidateForDrift())
                // ALSO demotes the Plan out of 'executing' in the SAME
                // write, which processUntilStoppedOrDone() detects and
                // stops on before ever reaching this method again --
                // finalize() only runs once zero pending Items remain
                // AND the Plan is still genuinely 'executing'. The branch
                // is kept because it is the structurally correct
                // completion rule, not dead code by design -- a future
                // per-Item failure mode that does NOT invalidate the
                // whole Plan (none exists today) would resume using it
                // automatically.
                $hasFailed = EnrollmentRolloverItem::query()
                    ->where('plan_id', $locked->id)
                    ->where('execution_status', 'failed')
                    ->exists();

                $newStatus = $hasFailed ? 'completed_with_errors' : 'completed';

                $locked->update([
                    'status' => $newStatus,
                    'completed_at' => now(),
                ]);

                $eventType = $hasFailed
                    ? 'enrollment_rollover.execution_completed_with_errors'
                    : 'enrollment_rollover.execution_completed';

                $this->audit->school($locked->school, $eventType, actor: $actor, subject: $locked, metadata: [
                    'planId' => $locked->id,
                    'configurationVersion' => $locked->configuration_version,
                    'summary' => $summary,
                ]);

                return [...$summary, 'planStatus' => $newStatus];
            });
        });
    }

    private function isExecutionReady(EnrollmentRolloverPlan $plan): bool
    {
        return $plan->status === 'executing' && $plan->validated_configuration_version === $plan->configuration_version;
    }

    /** @return Collection<int, string> */
    private function nextPendingItemIds(EnrollmentRolloverPlan $plan, int $batchSize): Collection
    {
        return $this->context->withSchool($plan->school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->where(fn ($q) => $q->whereNull('execution_status')->orWhereNotIn('execution_status', self::TERMINAL_ACCEPTED_EXECUTION_STATUSES))
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id'));
    }

    private function countPending(EnrollmentRolloverPlan $plan): int
    {
        return $this->context->withSchool($plan->school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->where(fn ($q) => $q->whereNull('execution_status')->orWhereNotIn('execution_status', self::TERMINAL_ACCEPTED_EXECUTION_STATUSES))
            ->count());
    }

    private function reloadPlan(EnrollmentRolloverPlan $plan): EnrollmentRolloverPlan
    {
        return $this->context->withSchool($plan->school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->firstOrFail());
    }

    /**
     * Durable summary, recomputed entirely from persisted Item rows
     * every time -- never an in-memory counter accumulated during the
     * loop (this checkpoint's brief, section 38: must be reconstructable
     * after a crash/restart from Plan + Items alone).
     *
     * @return array{total: int, succeeded: int, reconciled: int, skipped: int, failed: int, pending: int, planStatus: string}
     */
    private function summarize(EnrollmentRolloverPlan $plan): array
    {
        $counts = $this->context->withSchool($plan->school, fn () => DB::table('enrollment_rollover_items')
            ->where('plan_id', $plan->id)
            ->selectRaw('execution_status, count(*) as c')
            ->groupBy('execution_status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->execution_status ?? 'pending' => (int) $row->c]));

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
            'planStatus' => $plan->status,
        ];
    }
}
