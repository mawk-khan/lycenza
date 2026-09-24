<?php

namespace App\Domain\Automation\Application;

use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationExecutionAttempt;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one execution (ADR 0043 §5, §7), called by RunAutomationExecutionJob
 * inside the execution's School context.
 *
 * 1. Claim: one conditional UPDATE (status pending, or running with an
 *    expired lease; `attempts` unchanged since read) -> running + lease.
 *    Losing the race, a live lease, or a terminal row means do nothing.
 *    Reclaiming an expired lease records the crashed attempt as
 *    `interrupted`.
 * 2. In ONE transaction: re-check the School flag and the rule's status
 *    (skip if off), re-verify the owner (skip AND suspend the rule if not
 *    authorized), otherwise create the tier 0 review item; then finish the
 *    execution and append its attempt row. A crash anywhere rolls all of
 *    it back, and UNIQUE(execution_id) on review items makes a second item
 *    impossible regardless.
 * 3. An unexpected error is recorded as a failed attempt with a stable
 *    code; the execution is retried by `automation:executions-redispatch`
 *    after a backoff, until MAX_ATTEMPTS, then `abandoned`.
 */
class AutomationExecutionService
{
    public const MAX_ATTEMPTS = 3;

    public const LEASE_SECONDS = 60;

    /** Backoff (seconds) after failed attempt 1, 2, ... */
    public const BACKOFF_SECONDS = [60, 300];

    public function __construct(
        private readonly AutomationRuleCatalog $catalog,
        private readonly AutomationFeatureGate $gate,
        private readonly OwnerAuthorityVerifier $authority,
        private readonly AutomationRuleService $rules,
    ) {}

    /** @return bool whether this call claimed the execution and ran it */
    public function run(School $school, string $executionId): bool
    {
        $claim = $this->claim($executionId);

        if ($claim === null) {
            return false;
        }

        [$execution, $attemptNumber, $startedAt] = $claim;

        try {
            DB::transaction(function () use ($school, $execution, $attemptNumber, $startedAt): void {
                [$status, $code] = $this->evaluate($school, $execution);

                $execution->update([
                    'status' => $status,
                    'outcome_code' => $code,
                    'completed_at' => now(),
                    'processing_lease_expires_at' => null,
                ]);

                $this->recordAttempt($execution, $attemptNumber, $status === AutomationExecution::STATUS_SUCCEEDED ? 'succeeded' : 'skipped', $code, $startedAt);
            });
        } catch (Throwable $e) {
            Log::error('automation.execution.failed', [
                'school_id' => $school->id,
                'execution_id' => $execution->id,
                'attempt' => $attemptNumber,
                'exception' => $e::class,
            ]);

            $this->recordFailure($execution, $attemptNumber, $startedAt);
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string} status, outcome code
     */
    private function evaluate(School $school, AutomationExecution $execution): array
    {
        $instance = AutomationRuleInstance::query()->whereKey($execution->rule_instance_id)->lockForUpdate()->first();
        $ruleType = $instance === null ? null : $this->catalog->find($instance->rule_type);

        if ($instance === null || $ruleType === null) {
            return [AutomationExecution::STATUS_SKIPPED, 'rule_type_unregistered'];
        }

        if (! $this->gate->isEnabledFor($school)) {
            return [AutomationExecution::STATUS_SKIPPED, 'automation_disabled_for_school'];
        }

        if ($instance->status !== AutomationRuleInstance::STATUS_ENABLED) {
            return [AutomationExecution::STATUS_SKIPPED, 'rule_not_enabled'];
        }

        $owner = $instance->owner_user_id === null ? null : User::query()->find($instance->owner_user_id);
        $failure = $this->authority->failureFor($owner, $ruleType, $school);

        if ($failure !== null) {
            $this->rules->suspend($school, $instance, $failure, $execution->id);

            return [AutomationExecution::STATUS_SKIPPED, $failure];
        }

        AutomationReviewItem::query()->create([
            'school_id' => $school->id,
            'rule_instance_id' => $instance->id,
            'execution_id' => $execution->id,
            'item_type' => $ruleType->reviewItemType(),
            'subject_type' => $execution->subject_type,
            'subject_id' => $execution->subject_id,
        ]);

        return [AutomationExecution::STATUS_SUCCEEDED, 'review_item_created'];
    }

    /**
     * @return array{0: AutomationExecution, 1: int, 2: Carbon}|null
     */
    private function claim(string $executionId): ?array
    {
        $seen = AutomationExecution::query()->find($executionId);

        if ($seen === null || ! in_array($seen->status, [AutomationExecution::STATUS_PENDING, AutomationExecution::STATUS_RUNNING], true)) {
            return null;
        }

        $leaseExpired = $seen->processing_lease_expires_at === null || $seen->processing_lease_expires_at->isPast();

        if ($seen->status === AutomationExecution::STATUS_RUNNING && ! $leaseExpired) {
            return null;
        }

        if ($seen->attempts >= self::MAX_ATTEMPTS) {
            $abandoned = AutomationExecution::query()
                ->whereKey($seen->id)
                ->where('attempts', $seen->attempts)
                ->whereIn('status', [AutomationExecution::STATUS_PENDING, AutomationExecution::STATUS_RUNNING])
                ->update(['status' => AutomationExecution::STATUS_ABANDONED, 'outcome_code' => 'attempts_exhausted', 'completed_at' => now(), 'processing_lease_expires_at' => null]);

            if ($abandoned === 1 && $seen->status === AutomationExecution::STATUS_RUNNING) {
                $this->recordAttempt($seen, $seen->attempts, 'interrupted', 'lease_expired', null);
            }

            return null;
        }

        $claimed = AutomationExecution::query()
            ->whereKey($seen->id)
            ->where('attempts', $seen->attempts)
            ->where('status', $seen->status)
            ->where(fn ($q) => $q->whereNull('processing_lease_expires_at')->orWhere('processing_lease_expires_at', '<', now()))
            ->update([
                'status' => AutomationExecution::STATUS_RUNNING,
                'attempts' => $seen->attempts + 1,
                'processing_lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'next_attempt_at' => null,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        if ($seen->status === AutomationExecution::STATUS_RUNNING) {
            $this->recordAttempt($seen, $seen->attempts, 'interrupted', 'lease_expired', null);
        }

        return [AutomationExecution::query()->findOrFail($seen->id), $seen->attempts + 1, now()];
    }

    private function recordFailure(AutomationExecution $execution, int $attemptNumber, Carbon $startedAt): void
    {
        $exhausted = $attemptNumber >= self::MAX_ATTEMPTS;
        $delay = self::BACKOFF_SECONDS[min($attemptNumber - 1, count(self::BACKOFF_SECONDS) - 1)];

        DB::transaction(function () use ($execution, $attemptNumber, $startedAt, $exhausted, $delay): void {
            AutomationExecution::query()->whereKey($execution->id)->where('status', AutomationExecution::STATUS_RUNNING)->update($exhausted
                ? ['status' => AutomationExecution::STATUS_ABANDONED, 'outcome_code' => 'attempts_exhausted', 'completed_at' => now(), 'processing_lease_expires_at' => null]
                : ['status' => AutomationExecution::STATUS_PENDING, 'outcome_code' => 'unexpected_error', 'next_attempt_at' => now()->addSeconds($delay), 'processing_lease_expires_at' => null]);

            $this->recordAttempt($execution, $attemptNumber, 'failed', 'unexpected_error', $startedAt);
        });
    }

    private function recordAttempt(AutomationExecution $execution, int $attemptNumber, string $outcome, ?string $code, ?Carbon $startedAt): void
    {
        AutomationExecutionAttempt::query()->create([
            'school_id' => $execution->school_id,
            'execution_id' => $execution->id,
            'attempt_number' => $attemptNumber,
            'outcome' => $outcome,
            'outcome_code' => $code,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
