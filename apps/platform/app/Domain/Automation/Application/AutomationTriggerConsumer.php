<?php

namespace App\Domain\Automation\Application;

use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use App\Domain\Automation\Application\Catalog\AutomationRuleType;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Events\EventConsumer;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\SchoolOperationalGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single Automation outbox consumer (ADR 0043 §2), registered in
 * EventConsumerRegistry for exactly the catalog's trigger event types and
 * run by ProcessOutboxEventJob through IdempotentConsumerGuard (receipt in
 * the same transaction). Like WebhookFanoutConsumer it never acts inline:
 * it only claims execution rows and queues RunAutomationExecutionJob after
 * commit.
 *
 * Skips, in order: events without a School; Automation-originated events
 * (loop guard, AutomationOrigin); Schools whose `automation.rules` flag is
 * off; rule instances not enabled, or enabled after the event occurred
 * (never retroactive); events the rule's condition does not match. The
 * execution row's UNIQUE(school, rule instance, trigger key) makes a
 * duplicate delivery of the same event a no-op even without the receipt.
 */
class AutomationTriggerConsumer implements EventConsumer
{
    public const NAME = 'automation-trigger';

    /** Seconds before an undispatched/unclaimed pending execution is re-dispatched. */
    public const REDISPATCH_GRACE_SECONDS = 60;

    /**
     * Only the stateless catalog is injected: EventConsumerRegistry is a
     * singleton that builds this consumer once per worker process, while
     * TenantContext (and everything holding it -- the feature gate, the
     * rule service) is scoped to one job. Those are resolved per event in
     * handle(), never captured here.
     */
    public function __construct(private readonly AutomationRuleCatalog $catalog) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(string $eventType): bool
    {
        return $this->catalog->handlesEventType($eventType);
    }

    public function handle(DomainEventOutbox $event): void
    {
        if ($event->school_id === null) {
            return;
        }

        if (AutomationOrigin::isAutomationOriginated($event)) {
            Log::info('automation.trigger.ignored_automation_origin', ['event_id' => $event->id, 'school_id' => $event->school_id]);

            return;
        }

        $school = School::query()->find($event->school_id);

        // Phase 0N.9 (ADR 0047 section 8): no new execution for a School
        // that is not active; the outbox row stays recorded as dispatched.
        if ($school === null || ! $school->isActive() || ! app(AutomationFeatureGate::class)->isEnabledFor($school)) {
            return;
        }

        $rules = app(AutomationRuleService::class);

        foreach ($this->catalog->forEventType($event->event_type) as $ruleType) {
            $this->trigger($school, $ruleType, $event, $rules);
        }
    }

    private function trigger(School $school, AutomationRuleType $ruleType, DomainEventOutbox $event, AutomationRuleService $rules): void
    {
        $instance = AutomationRuleInstance::query()
            ->where('school_id', $school->id)
            ->where('rule_type', $ruleType->key())
            ->where('status', AutomationRuleInstance::STATUS_ENABLED)
            ->where('enabled_at', '<=', $event->occurred_at)
            ->lockForUpdate()
            ->first();

        if ($instance === null) {
            return;
        }

        $subject = $ruleType->subjectFor($event);

        if ($subject === null) {
            Log::warning('automation.trigger.condition_not_met', ['event_id' => $event->id, 'rule_type' => $ruleType->key()]);

            return;
        }

        $recent = AutomationExecution::query()
            ->where('rule_instance_id', $instance->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $ruleType->maxExecutionsPerDay()) {
            $rules->suspend($school, $instance, 'execution_cap_exceeded');

            return;
        }

        try {
            $execution = DB::transaction(fn () => ! app(SchoolOperationalGuard::class)->holdOperational($school->id) ? null : AutomationExecution::query()->create([
                'school_id' => $school->id,
                'rule_instance_id' => $instance->id,
                'trigger_key' => $event->id,
                'trigger_event_type' => $event->event_type,
                'subject_type' => $subject['type'],
                'subject_id' => $subject['id'],
                'correlation_id' => $event->correlation_id,
                'status' => AutomationExecution::STATUS_PENDING,
                'next_attempt_at' => now()->addSeconds(self::REDISPATCH_GRACE_SECONDS),
            ]));
        } catch (UniqueConstraintViolationException) {
            return; // this occurrence already has its execution
        }

        if ($execution === null) {
            return; // suspended while the trigger was being evaluated
        }

        RunAutomationExecutionJob::dispatch($school->id, $execution->id)
            ->onQueue(QueueName::Default->value)
            ->afterCommit();
    }
}
