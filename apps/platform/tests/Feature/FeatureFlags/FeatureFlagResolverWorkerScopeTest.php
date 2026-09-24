<?php

namespace Tests\Feature\FeatureFlags;

use App\Domain\Automation\Application\AutomationFeatureGate;
use App\Domain\Automation\Application\AutomationRuleService;
use App\Domain\Automation\Application\AutomationTriggerConsumer;
use App\Domain\Automation\Application\Catalog\AcademicYearSetupReviewRule;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Models\Campus;
use App\Models\DomainEventOutbox;
use App\Models\FeatureFlagSchoolOverride;
use App\Support\Events\EventConsumerRegistry;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Regression (found in Phase 0L.6): a long-running queue worker rebuilds
 * scoped instances -- TenantContext among them -- between jobs
 * (Application::forgetScopedInstances()). Anything that outlives a job
 * must not hold the previous job's TenantContext: resolving a feature flag
 * in a later job used to restore that stale context and reset the RLS
 * session variable, so the rest of the job silently saw no tenant rows.
 */
class FeatureFlagResolverWorkerScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_flag_check_in_a_later_job_keeps_that_jobs_school_context(): void
    {
        $school = $this->createSchool();
        $campus = app(TenantContext::class)->withSchool($school, fn () => $this->createCampus($school));

        app(FeatureFlagResolver::class); // resolved during an earlier "job"
        $this->app->forgetScopedInstances(); // what the worker does between jobs

        $context = app(TenantContext::class);
        $context->set($school);
        app(FeatureFlagResolver::class)->isEnabledForSchool(AutomationFeatureGate::FLAG, $school);

        $this->assertSame($school->id, $context->school()?->id);
        $this->assertSame([$campus->id], Campus::query()->pluck('id')->all(), 'Tenant rows still visible under RLS after the flag check.');
        $context->clearAll();
    }

    #[Test]
    public function the_automation_consumer_works_in_every_job_of_a_long_running_worker(): void
    {
        $school = $this->createSchool();
        $owner = $this->createUser();
        $this->assignSchoolRole($this->createMembership($owner, $school), 'school_admin');
        app(TenantContext::class)->withSchool($school, fn () => FeatureFlagSchoolOverride::query()->create([
            'school_id' => $school->id, 'feature_flag_key' => AutomationFeatureGate::FLAG, 'enabled' => true,
        ]));
        app(FeatureFlagResolver::class)->forgetCache(AutomationFeatureGate::FLAG, $school);
        app(AutomationRuleService::class)->enable($school, AcademicYearSetupReviewRule::KEY, $owner);

        // The registry (a singleton) builds its consumers in the first job.
        app(EventConsumerRegistry::class);

        foreach ([1, 2] as $job) {
            $this->app->forgetScopedInstances();
            $event = DomainEventOutbox::query()->create([
                'id' => (string) Str::uuid7(), 'event_type' => 'academic_year.activated.v1', 'event_version' => 1,
                'school_id' => $school->id, 'correlation_id' => (string) Str::uuid7(),
                'payload' => ['academicYearId' => (string) Str::uuid7(), 'previousActiveAcademicYearId' => null],
                'metadata' => [], 'occurred_at' => now()->addMinute(), 'available_at' => now(), 'status' => 'dispatched',
            ]);

            $context = app(TenantContext::class);
            $context->set($school);
            $consumer = collect(app(EventConsumerRegistry::class)->forEventType($event->event_type))
                ->first(fn ($c) => $c instanceof AutomationTriggerConsumer);
            $consumer->handle($event);

            // (The sync queue already ran the execution job, which clears the
            // context in `finally`; read inside the School's context.)
            $count = app(TenantContext::class)->withSchool($school, fn () => AutomationExecution::query()->where('trigger_key', $event->id)->count());
            $this->assertSame(1, $count, "Job {$job} created its execution.");
            $context->clearAll();
        }
    }
}
