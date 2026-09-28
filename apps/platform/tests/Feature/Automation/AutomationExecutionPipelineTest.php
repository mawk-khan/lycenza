<?php

namespace Tests\Feature\Automation;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\Automation\Application\AutomationExecutionService;
use App\Domain\Automation\Application\AutomationFeatureGate;
use App\Domain\Automation\Application\AutomationOrigin;
use App\Domain\Automation\Application\AutomationRuleService;
use App\Domain\Automation\Application\AutomationTriggerConsumer;
use App\Domain\Automation\Application\Catalog\AcademicYearSetupReviewRule;
use App\Domain\Automation\Application\Catalog\AutomationRuleCatalog;
use App\Domain\Automation\Application\Catalog\AutomationRuleType;
use App\Domain\Automation\Application\OwnerAuthorityVerifier;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationExecutionAttempt;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Jobs\ProcessOutboxEventJob;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\DomainEventOutbox;
use App\Models\FeatureFlagSchoolOverride;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Events\IdempotentConsumerGuard;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0L.6 -- the whole Automation chain on real PostgreSQL:
 * `academic_year.activated.v1` -> outbox -> AutomationTriggerConsumer ->
 * RunAutomationExecutionJob -> one tier 0 review item. The demo world
 * enables the `automation.rules` flag for the Demo School only and has its
 * School Admin enable the rule before the two demo academic years are
 * activated, so two real activation events are waiting in the outbox.
 */
class AutomationExecutionPipelineTest extends TestCase
{
    use CreatesTenancyFixtures;

    private DemoBuildResult $world;

    private function build(): DemoBuildResult
    {
        return $this->world = app(DemoDataBuilder::class)->build();
    }

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** Runs the outbox job for every pending activation event, as the worker would. */
    private function processActivationEvents(): void
    {
        foreach (DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('status', 'pending')->orderBy('occurred_at')->pluck('id') as $id) {
            ProcessOutboxEventJob::dispatchSync($id);
        }
    }

    private function executions(School $school): Collection
    {
        return $this->in($school, fn () => AutomationExecution::query()->where('school_id', $school->id)->orderBy('created_at')->orderBy('id')->get());
    }

    private function items(School $school): Collection
    {
        return $this->in($school, fn () => AutomationReviewItem::query()->where('school_id', $school->id)->get());
    }

    private function ruleInstance(School $school): ?AutomationRuleInstance
    {
        return $this->in($school, fn () => AutomationRuleInstance::query()->where('school_id', $school->id)->where('rule_type', AcademicYearSetupReviewRule::KEY)->first());
    }

    private function setFlag(School $school, bool $enabled): void
    {
        $this->in($school, fn () => FeatureFlagSchoolOverride::query()->updateOrCreate(
            ['school_id' => $school->id, 'feature_flag_key' => AutomationFeatureGate::FLAG],
            ['enabled' => $enabled],
        ));
        app(FeatureFlagResolver::class)->forgetCache(AutomationFeatureGate::FLAG, $school);
    }

    private function activateNewYear(School $school, User $actor, string $code): string
    {
        $years = app(AcademicYearService::class);
        $year = $this->in($school, fn () => $years->create($school, ['name' => $code, 'code' => $code, 'starts_on' => '2030-04-01', 'ends_on' => '2031-03-31'], $actor));
        $this->in($school, fn () => $years->activate($year, $actor));

        return $year->id;
    }

    #[Test]
    public function an_activation_event_produces_exactly_one_execution_and_one_review_item(): void
    {
        $w = $this->build();
        $this->processActivationEvents();

        $executions = $this->executions($w->school);
        $items = $this->items($w->school);

        $this->assertCount(2, $executions, 'Both demo activations (2025-26, then 2026-27).');
        $this->assertCount(2, $items);
        foreach ($executions as $execution) {
            $this->assertSame(AutomationExecution::STATUS_SUCCEEDED, $execution->status);
            $this->assertSame('review_item_created', $execution->outcome_code);
            $this->assertSame(1, $execution->attempts);
            $this->assertSame('academic_year', $execution->subject_type);
        }

        $activatedYears = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)
            ->get()->map(fn ($e) => $e->payload['academicYearId'])->sort()->values()->all();
        $this->assertSame($activatedYears, $items->pluck('subject_id')->sort()->values()->all());
        $this->assertSame(['academic_year_setup_review'], $items->pluck('item_type')->unique()->values()->all());

        $attempts = $this->in($w->school, fn () => AutomationExecutionAttempt::query()->where('school_id', $w->school->id)->get());
        $this->assertCount(2, $attempts);
        $this->assertSame(['succeeded'], $attempts->pluck('outcome')->unique()->values()->all());

        // The trigger key is the event id; nothing from the payload beyond the one id.
        $this->assertEqualsCanonicalizing(
            DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)->pluck('id')->all(),
            $executions->pluck('trigger_key')->all(),
        );
    }

    #[Test]
    public function duplicate_deliveries_and_repeated_jobs_never_create_a_second_execution_or_item(): void
    {
        $w = $this->build();
        $event = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)->orderBy('occurred_at')->firstOrFail();

        ProcessOutboxEventJob::dispatchSync($event->id);
        ProcessOutboxEventJob::dispatchSync($event->id); // redelivery: receipt no-ops

        // A consumer run that bypasses the receipt (e.g. a lost receipt):
        // the execution's own UNIQUE constraint still holds.
        $this->in($w->school, fn () => app(AutomationTriggerConsumer::class)->handle($event));

        $executions = $this->executions($w->school);
        $this->assertCount(1, $executions);

        // The job running again (duplicate dispatch, redispatch) is a no-op.
        RunAutomationExecutionJob::dispatchSync($w->school->id, $executions->first()->id);
        RunAutomationExecutionJob::dispatchSync($w->school->id, $executions->first()->id);

        $this->assertCount(1, $this->items($w->school));
        $this->assertSame(1, $this->executions($w->school)->first()->attempts);
    }

    #[Test]
    public function the_school_feature_flag_and_the_rule_state_both_gate_execution(): void
    {
        $w = $this->build();

        // Flag OFF for the Demo School: nothing.
        $this->setFlag($w->school, false);
        $this->processActivationEvents();
        $this->assertCount(0, $this->executions($w->school));

        // Annexe: rule enabled by its own admin, but its flag was never
        // turned on -- the Demo School's flag cannot enable it.
        $this->setFlag($w->school, true);
        $annexeAdmin = $this->user('annexe.admin@example.test');
        app(AutomationRuleService::class)->enable($w->secondSchool, AcademicYearSetupReviewRule::KEY, $annexeAdmin);
        Carbon::setTestNow(now()->addMinute());
        $this->activateNewYear($w->secondSchool, $annexeAdmin, 'AX2030');
        $this->processActivationEvents();
        $this->assertCount(0, $this->executions($w->secondSchool));

        // Flag ON + rule disabled: nothing.
        app(AutomationRuleService::class)->disable($w->school, AcademicYearSetupReviewRule::KEY, $this->user('school.admin@example.test'));
        $this->activateNewYear($w->school, $this->user('school.admin@example.test'), 'AY2030');
        $this->processActivationEvents();
        $this->assertCount(0, $this->executions($w->school));
    }

    #[Test]
    public function a_rule_never_reacts_to_events_that_happened_before_it_was_enabled(): void
    {
        $w = $this->build();
        $admin = $this->user('school.admin@example.test');

        app(AutomationRuleService::class)->disable($w->school, AcademicYearSetupReviewRule::KEY, $admin);
        Carbon::setTestNow(now()->addMinute());
        app(AutomationRuleService::class)->enable($w->school, AcademicYearSetupReviewRule::KEY, $admin);

        $this->processActivationEvents();
        $this->assertCount(0, $this->executions($w->school));
    }

    #[Test]
    public function the_flag_switched_off_after_triggering_skips_the_execution_without_suspending(): void
    {
        $w = $this->build();
        $event = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)->orderBy('occurred_at')->firstOrFail();

        // Create the execution without running its job yet.
        $this->in($w->school, fn () => app(IdempotentConsumerGuard::class)->run(app(AutomationTriggerConsumer::class), $event));
        $execution = $this->executions($w->school)->first();
        $this->assertSame(AutomationExecution::STATUS_SUCCEEDED, $execution->status, 'sync queue: the job already ran');

        // A second occurrence, flag switched off between trigger and run.
        $admin = $this->user('school.admin@example.test');
        Carbon::setTestNow(now()->addMinute());
        $yearId = $this->activateNewYear($w->school, $admin, 'AY2031');
        $pending = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('payload->academicYearId', $yearId)->firstOrFail();
        $created = $this->in($w->school, fn () => AutomationExecution::query()->create([
            'school_id' => $w->school->id, 'rule_instance_id' => $this->ruleInstance($w->school)->id,
            'trigger_key' => $pending->id, 'trigger_event_type' => $pending->event_type,
            'subject_type' => 'academic_year', 'subject_id' => $yearId, 'status' => AutomationExecution::STATUS_PENDING,
        ]));
        $this->setFlag($w->school, false);

        RunAutomationExecutionJob::dispatchSync($w->school->id, $created->id);

        $fresh = $this->in($w->school, fn () => AutomationExecution::query()->findOrFail($created->id));
        $this->assertSame(AutomationExecution::STATUS_SKIPPED, $fresh->status);
        $this->assertSame('automation_disabled_for_school', $fresh->outcome_code);
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $this->ruleInstance($w->school)->status);
        $this->assertSame(0, $this->items($w->school)->where('subject_id', $yearId)->count());
    }

    /** @return array<string, array{0: callable(DemoBuildResult, User): void, 1: string}> */
    public static function authorityLosses(): array
    {
        return [
            'owner account disabled' => [function (DemoBuildResult $w, User $owner): void {
                $owner->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
            }, OwnerAuthorityVerifier::OWNER_DISABLED],
            'owner membership no longer active' => [function (DemoBuildResult $w, User $owner): void {
                app(TenantContext::class)->withSchool($w->school, fn () => SchoolMembership::query()
                    ->where('user_id', $owner->id)->where('school_id', $w->school->id)->update(['status' => 'suspended']));
            }, OwnerAuthorityVerifier::OWNER_NO_SCHOOL_AUTHORITY],
            'owner lost automation.manage' => [function (DemoBuildResult $w, User $owner): void {
                app(TenantContext::class)->withSchool($w->school, function () use ($w, $owner): void {
                    $membership = SchoolMembership::query()->where('user_id', $owner->id)->where('school_id', $w->school->id)->firstOrFail();
                    MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->active()->update(['revoked_at' => now(), 'revocation_reason' => MembershipRoleAssignment::REASON_REVOKED]);
                    MembershipRoleAssignment::query()->create([
                        'school_membership_id' => $membership->id,
                        'role_id' => Role::query()->where('key', 'principal')->value('id'),
                        'school_id' => $w->school->id,
                    ]);
                });
            }, OwnerAuthorityVerifier::OWNER_CAPABILITY_MISSING],
        ];
    }

    #[Test]
    #[DataProvider('authorityLosses')]
    public function losing_authority_between_configuration_and_execution_skips_and_suspends(callable $revoke, string $expectedReason): void
    {
        $w = $this->build();
        $owner = $this->user('school.admin@example.test');
        $revoke($w, $owner);
        app(CapabilityResolver::class)->forgetCache($owner, $w->school);

        $this->processActivationEvents();

        // The first activation's execution finds the lost authority and
        // suspends the rule; the second activation then finds no enabled
        // rule, so no execution is even created for it.
        $executions = $this->executions($w->school);
        $this->assertCount(1, $executions);
        $this->assertSame(AutomationExecution::STATUS_SKIPPED, $executions->first()->status);
        $this->assertSame($expectedReason, $executions->first()->outcome_code);
        $this->assertCount(0, $this->items($w->school), 'No tier 0 effect without authority.');

        $instance = $this->ruleInstance($w->school);
        $this->assertSame(AutomationRuleInstance::STATUS_SUSPENDED, $instance->status);
        $this->assertSame($expectedReason, $instance->suspension_reason);

        $suspensions = $this->in($w->school, fn () => SchoolAuditEvent::query()->where('school_id', $w->school->id)->where('event_type', 'automation.rule.suspended')->get());
        $this->assertCount(1, $suspensions);
        $this->assertNull($suspensions->first()->getAttribute('actor_user_id'), 'A suspension is a system consequence, not attributed to a human.');
        $this->assertSame($expectedReason, $suspensions->first()->metadata['reason']);
        $this->assertSame($executions->first()->id, $suspensions->first()->metadata['executionId']);

        // Never silently switched to another administrator: a different
        // manager must re-enable it, becoming its owner.
        $manager = $this->createUser();
        $this->assignSchoolRole($this->createMembership($manager, $w->school), 'school_admin');
        $reenabled = app(AutomationRuleService::class)->enable($w->school, AcademicYearSetupReviewRule::KEY, $manager);
        $this->assertSame(AutomationRuleInstance::STATUS_ENABLED, $reenabled->status);
        $this->assertSame($manager->id, $reenabled->owner_user_id);
        $this->assertNull($reenabled->suspension_reason);

        Carbon::setTestNow(now()->addMinute());
        $this->activateNewYear($w->school, $manager, 'AY2032');
        $this->processActivationEvents();
        $this->assertCount(1, $this->items($w->school));
    }

    #[Test]
    public function automation_originated_events_never_trigger_a_rule(): void
    {
        $w = $this->build();
        $event = DomainEventOutbox::query()->create([
            'id' => (string) Str::uuid7(), 'event_type' => 'academic_year.activated.v1', 'event_version' => 1,
            'school_id' => $w->school->id, 'correlation_id' => (string) Str::uuid7(),
            'payload' => ['academicYearId' => (string) Str::uuid7(), 'previousActiveAcademicYearId' => null],
            'metadata' => AutomationOrigin::metadataFor((string) Str::uuid7()),
            'occurred_at' => now()->addMinute(), 'available_at' => now(), 'status' => 'pending',
        ]);

        ProcessOutboxEventJob::dispatchSync($event->id);

        $this->assertCount(0, $this->executions($w->school)->where('trigger_key', $event->id));
    }

    #[Test]
    public function an_interrupted_attempt_is_recorded_and_the_retry_creates_the_item_once(): void
    {
        $w = $this->build();
        $event = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)->orderBy('occurred_at')->firstOrFail();
        $execution = $this->in($w->school, fn () => AutomationExecution::query()->create([
            'school_id' => $w->school->id, 'rule_instance_id' => $this->ruleInstance($w->school)->id,
            'trigger_key' => $event->id, 'trigger_event_type' => $event->event_type,
            'subject_type' => 'academic_year', 'subject_id' => $event->payload['academicYearId'],
            // A worker claimed it (attempt 1) and crashed: its lease has expired.
            'status' => AutomationExecution::STATUS_RUNNING, 'attempts' => 1,
            'processing_lease_expires_at' => now()->subSecond(),
        ]));

        // A live lease is left alone.
        $this->in($w->school, fn () => AutomationExecution::query()->whereKey($execution->id)->update(['processing_lease_expires_at' => now()->addMinute()]));
        RunAutomationExecutionJob::dispatchSync($w->school->id, $execution->id);
        $this->assertCount(0, $this->items($w->school));

        // Expired: the redispatch command picks it up and the retry succeeds.
        $this->in($w->school, fn () => AutomationExecution::query()->whereKey($execution->id)->update(['processing_lease_expires_at' => now()->subSecond()]));
        Artisan::call('automation:executions-redispatch');

        $fresh = $this->in($w->school, fn () => AutomationExecution::query()->findOrFail($execution->id));
        $this->assertSame(AutomationExecution::STATUS_SUCCEEDED, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
        $attempts = $this->in($w->school, fn () => AutomationExecutionAttempt::query()->where('execution_id', $execution->id)->orderBy('attempt_number')->get());
        $this->assertSame([[1, 'interrupted', 'lease_expired'], [2, 'succeeded', 'review_item_created']], $attempts->map(fn ($a) => [$a->attempt_number, $a->outcome, $a->outcome_code])->all());
        $this->assertCount(1, $this->items($w->school));
    }

    #[Test]
    public function unexpected_failures_retry_with_backoff_and_are_abandoned_after_the_attempt_limit(): void
    {
        $w = $this->build();
        // A catalog whose rule type produces an item the database rejects,
        // so every attempt fails in the action.
        $this->app->instance(AutomationRuleCatalog::class, new class extends AutomationRuleCatalog
        {
            public function all(): array
            {
                return [new class implements AutomationRuleType
                {
                    public function key(): string
                    {
                        return AcademicYearSetupReviewRule::KEY;
                    }

                    public function label(): string
                    {
                        return 'broken';
                    }

                    public function description(): string
                    {
                        return 'broken';
                    }

                    public function triggerEventType(): string
                    {
                        return 'academic_year.activated.v1';
                    }

                    public function tier(): int
                    {
                        return 0;
                    }

                    public function requiredCapabilities(): array
                    {
                        return ['automation.manage', 'academics.years.view'];
                    }

                    public function maxExecutionsPerDay(): int
                    {
                        return 20;
                    }

                    public function reviewItemType(): string
                    {
                        return 'not_an_allowed_item_type';
                    }

                    public function subjectFor(DomainEventOutbox $event): ?array
                    {
                        return (new AcademicYearSetupReviewRule)->subjectFor($event);
                    }
                }];
            }
        });

        $event = DomainEventOutbox::query()->where('event_type', 'academic_year.activated.v1')->where('school_id', $w->school->id)->orderBy('occurred_at')->firstOrFail();
        ProcessOutboxEventJob::dispatchSync($event->id);
        $execution = $this->executions($w->school)->first();

        $this->assertSame(AutomationExecution::STATUS_PENDING, $execution->status);
        $this->assertSame('unexpected_error', $execution->outcome_code);
        $this->assertTrue($execution->next_attempt_at->isFuture(), 'Backoff before the next attempt.');

        Artisan::call('automation:executions-redispatch');
        $this->assertSame(1, $this->in($w->school, fn () => AutomationExecution::query()->findOrFail($execution->id))->attempts, 'Not due yet.');

        for ($attempt = 2; $attempt <= AutomationExecutionService::MAX_ATTEMPTS; $attempt++) {
            Carbon::setTestNow(now()->addMinutes(10));
            Artisan::call('automation:executions-redispatch');
        }

        $fresh = $this->in($w->school, fn () => AutomationExecution::query()->findOrFail($execution->id));
        $this->assertSame(AutomationExecution::STATUS_ABANDONED, $fresh->status);
        $this->assertSame(AutomationExecutionService::MAX_ATTEMPTS, $fresh->attempts);
        $attempts = $this->in($w->school, fn () => AutomationExecutionAttempt::query()->where('execution_id', $execution->id)->pluck('outcome_code', 'attempt_number')->all());
        $this->assertSame([1 => 'unexpected_error', 2 => 'unexpected_error', 3 => 'unexpected_error'], $attempts);
        $this->assertCount(0, $this->items($w->school));

        Carbon::setTestNow(now()->addHour());
        Artisan::call('automation:executions-redispatch');
        $this->assertSame(AutomationExecutionService::MAX_ATTEMPTS, $this->in($w->school, fn () => AutomationExecution::query()->findOrFail($execution->id))->attempts, 'Abandoned stays abandoned.');
    }

    #[Test]
    public function the_daily_execution_cap_suspends_a_runaway_rule(): void
    {
        $w = $this->build();
        $instance = $this->ruleInstance($w->school);
        for ($i = 0; $i < (new AcademicYearSetupReviewRule)->maxExecutionsPerDay(); $i++) {
            $this->in($w->school, fn () => AutomationExecution::query()->create([
                'school_id' => $w->school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => 'cap-'.$i,
                'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
                'subject_id' => (string) Str::uuid7(), 'status' => AutomationExecution::STATUS_SUCCEEDED,
            ]));
        }

        $this->processActivationEvents();

        $this->assertSame(AutomationRuleInstance::STATUS_SUSPENDED, $this->ruleInstance($w->school)->status);
        $this->assertSame('execution_cap_exceeded', $this->ruleInstance($w->school)->suspension_reason);
        $this->assertCount(0, $this->items($w->school));
    }
}
