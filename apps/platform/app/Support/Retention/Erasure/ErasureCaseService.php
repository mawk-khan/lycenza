<?php

namespace App\Support\Retention\Erasure;

use App\Models\ErasureCase;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\MetricsRecorder;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D10, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): the reviewed data-subject
 * erasure case lifecycle. It is the ONLY writer of `erasure_cases`, and it
 * is reached only from the operator console commands, never from a request
 * or a schedule.
 *
 * Lifecycle: requested -> approved | partially_approved | denied;
 * approved/partially_approved -> executing -> completed.
 * - Approval never overrides retention. Execution removes only what the
 *   domain adapters report ELIGIBLE (an adopted period already passed,
 *   nothing retained needs it, no hold). Everything else stays, with its
 *   reason recorded in the case outcome.
 * - The internal target is 30 calendar days after the decision, in the
 *   School's local date. It is an operational target, never a statutory
 *   claim. Missing it only makes the case visible as overdue; nothing
 *   happens automatically.
 * - Execution is safe to rerun.
 * - Audit and metrics carry codes and counts only, never the subject's
 *   data.
 */
final class ErasureCaseService
{
    public const TARGET_DAYS = 30;

    public const CHANNELS = ['written', 'email', 'in_person', 'other'];

    public const DECISIONS = ['approve' => 'approved', 'partially_approve' => 'partially_approved', 'deny' => 'denied'];

    public const REASONS = ['request_valid', 'request_valid_with_retained_categories', 'identity_not_verified', 'retention_obligation', 'legal_hold', 'not_applicable'];

    public function __construct(
        private readonly DataSubjectErasurePlanner $planner,
        private readonly AuditRecorder $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function open(?School $school, string $subjectType, string $subjectId, string $channel): ErasureCase
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new ErasureCaseException('Unknown request channel.');
        }

        $adapter = $this->planner->adapter($subjectType);
        if (($subjectType === 'user') !== ($school === null)) {
            throw new ErasureCaseException('A User case is platform-scope; every other subject belongs to exactly one School.');
        }

        if (! $adapter->exists($school, $subjectId)) {
            throw new ErasureCaseException('No such subject in this scope.');
        }

        $case = DB::transaction(function () use ($school, $subjectType, $subjectId, $channel): ErasureCase {
            $case = new ErasureCase;
            $case->forceFill([
                'scope' => $school === null ? 'platform' : 'school',
                'school_id' => $school?->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'request_channel' => $channel,
                'status' => 'requested',
                'requested_at' => now(),
            ])->save();
            $this->audit->platform('platform.erasure_case.opened', subject: $case, metadata: ['scope' => $case->scope, 'subject_type' => $subjectType, 'school_id' => $school?->id]);

            return $case;
        });

        $this->transition('requested');

        return $case;
    }

    public function decide(string $caseId, string $decision, string $reason): ErasureCase
    {
        $status = self::DECISIONS[$decision] ?? throw new ErasureCaseException('Unknown decision.');
        if (! in_array($reason, self::REASONS, true)) {
            throw new ErasureCaseException('Unknown decision reason.');
        }

        $case = DB::transaction(function () use ($caseId, $status, $reason): ErasureCase {
            $case = ErasureCase::query()->whereKey($caseId)->lockForUpdate()->first() ?? throw new ErasureCaseException('No such case.');
            if ($case->status !== 'requested') {
                throw new ErasureCaseException('Only a requested case can be decided.');
            }

            $now = CarbonImmutable::now();
            $case->forceFill([
                'status' => $status,
                'decided_at' => $now,
                'decision_reason' => $reason,
                'target_on' => $status === 'denied' ? null : $this->localDate($case, $now)->addDays(self::TARGET_DAYS)->toDateString(),
            ])->save();
            $this->audit->platform('platform.erasure_case.decided', subject: $case, metadata: ['status' => $status, 'reason' => $reason]);

            return $case;
        });

        $this->transition($status);

        return $case;
    }

    /**
     * Plans (dry run: changes nothing) or executes an approved case. Every
     * category is rechecked by its domain immediately before anything is
     * removed.
     *
     * @return list<ErasureCategory>
     */
    public function execute(string $caseId, bool $dryRun): array
    {
        $case = ErasureCase::query()->find($caseId) ?? throw new ErasureCaseException('No such case.');
        if (! in_array($case->status, ['approved', 'partially_approved', 'executing', 'completed'], true)) {
            throw new ErasureCaseException('Only an approved case can be executed.');
        }

        if ($dryRun) {
            return $this->planner->plan($case);
        }

        DB::transaction(function () use ($caseId): void {
            $locked = ErasureCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'completed') {
                $locked->forceFill(['status' => 'executing', 'execution_started_at' => $locked->execution_started_at ?? now()])->save();
            }
        });
        $this->transition('executing');

        $outcome = $this->planner->execute($case);

        DB::transaction(function () use ($caseId, $outcome): void {
            $locked = ErasureCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => 'completed',
                'completed_at' => $locked->completed_at ?? now(),
                'outcome' => array_map(fn (ErasureCategory $c) => $c->toArray(), $outcome),
            ])->save();
            $this->audit->platform('platform.erasure_case.executed', subject: $locked, metadata: [
                'outcomes' => array_count_values(array_map(fn (ErasureCategory $c) => $c->outcome, $outcome)),
            ]);
        });
        $this->transition('completed');

        return $outcome;
    }

    /** Approved or executing cases past their 30-day target (operational visibility only). */
    public function overdueCount(): int
    {
        return ErasureCase::query()->whereIn('status', ['approved', 'partially_approved', 'executing'])
            ->where('target_on', '<', CarbonImmutable::now()->toDateString())->count();
    }

    private function localDate(ErasureCase $case, CarbonImmutable $at): CarbonImmutable
    {
        $school = $case->school_id === null ? null : School::query()->find($case->school_id);

        return $school === null ? $at->startOfDay() : $at->setTimezone(SchoolTimezone::resolve($school))->startOfDay();
    }

    private function transition(string $state): void
    {
        $this->metrics->counter('lycenza_erasure_case_transitions_total', 1, ['state' => $state]);
    }
}
