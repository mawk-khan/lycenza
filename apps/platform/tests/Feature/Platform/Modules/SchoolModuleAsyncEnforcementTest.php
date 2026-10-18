<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Automation\Application\AutomationExecutionService;
use App\Domain\Automation\Application\AutomationFeatureGate;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\DomainEventOutbox;
use App\Models\FeatureFlagSchoolOverride;
use App\Models\School;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\SsrfSafeUrlValidator;
use App\Support\Webhooks\WebhookSigner;
use App\Support\Webhooks\WebhookSubscriptionService;
use Database\Factories\SchoolFactory;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §12.7(3), §31): background work of an optional module
 * re-checks the entitlement when it EXECUTES. Work queued while the module
 * was enabled ends in its substrate's terminal outcome once the module is
 * disabled -- never sent later, never retried in a loop. The fee runs are
 * proven in FeeAssessmentRunServiceTest / LateFeeServiceTest.
 */
class SchoolModuleAsyncEnforcementTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function disable(School $school, string ...$modules): void
    {
        SchoolFactory::setOptionalModules($school, false, $modules);
    }

    // --- Communications ---------------------------------------------------------------

    #[Test]
    public function a_queued_communication_delivery_is_cancelled_once_and_never_retried(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $delivery = $this->createDelivery($this->createRecipient($this->createMessage($this->createThread($school, $sender), $sender), $recipientUser));

        $this->disable($school, 'communications');
        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );

        $cancelled = $this->in($school, fn () => $delivery->fresh());
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('module_not_entitled', $cancelled->failure_code);
        $this->assertNull($cancelled->next_attempt_at);
        $this->assertSame(0, $cancelled->attempts);
        $this->assertSame(0, $this->in($school, fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count()));

        // Re-enabling sends nothing that was cancelled: no replay, no loop.
        SchoolFactory::setOptionalModules($school, true, ['communications']);
        $this->travel(5)->minutes();
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame('cancelled', $this->in($school, fn () => $delivery->fresh()->status));
    }

    #[Test]
    public function a_scheduled_announcement_is_held_while_communications_is_disabled(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $service = app(AnnouncementService::class);
        $scheduled = $service->schedule(
            $service->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide),
            $creator,
            now()->addMinute(),
        );

        $this->disable($school, 'communications');
        $this->travel(2)->minutes();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);
        $this->assertSame('scheduled', $this->in($school, fn () => $scheduled->fresh()->status));

        SchoolFactory::setOptionalModules($school, true, ['communications']);
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);
        $this->assertSame('published', $this->in($school, fn () => $scheduled->fresh()->status));
    }

    // --- Integrations -----------------------------------------------------------------

    private function subscribedEndpoint(School $school, User $user): WebhookEndpoint
    {
        return $this->in($school, function () use ($school, $user) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id, 'name' => 'test', 'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret', 'status' => 'active',
            ]);
            app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1', $user);

            return $endpoint;
        });
    }

    #[Test]
    public function no_webhook_delivery_is_created_for_a_school_without_integrations(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->subscribedEndpoint($school, $user);

        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $this->disable($school, 'integrations');
        Artisan::call('platform:outbox-dispatch');

        $this->assertSame(0, $this->in($school, fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->count()));
        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'school.setting.changed.v1')->where('status', 'pending')->count(), 'The event is acknowledged, not retried.');
        Http::assertNothingSent();
    }

    #[Test]
    public function a_queued_webhook_delivery_is_abandoned_without_an_http_call(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->subscribedEndpoint($school, $user);
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'school.setting.changed.v1')->latest('occurred_at')->firstOrFail();
        $delivery = $this->in($school, fn () => $endpoint->deliveries()->create([
            'school_id' => $school->id, 'event_id' => $event->id, 'event_type' => $event->event_type, 'status' => 'pending',
        ]));

        $this->disable($school, 'integrations');
        (new DeliverWebhookJob($school->id, $delivery->id))->handle(app(SsrfSafeUrlValidator::class), app(WebhookSigner::class), app(TenantContext::class));

        $abandoned = $this->in($school, fn () => $delivery->fresh());
        $this->assertSame('abandoned', $abandoned->status);
        $this->assertSame(0, $abandoned->attempts);
        $this->assertSame(0, $this->in($school, fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count()));
        Http::assertNothingSent();

        SchoolFactory::setOptionalModules($school, true, ['integrations']);
        $this->travel(10)->minutes();
        Artisan::call('platform:webhook-deliveries-redispatch');
        $this->assertSame('abandoned', $this->in($school, fn () => $delivery->fresh()->status));
        Http::assertNothingSent();
    }

    // --- Automation: entitlement AND the automation.rules flag -----------------------

    private function setFlag(School $school, bool $enabled): void
    {
        $this->in($school, fn () => FeatureFlagSchoolOverride::query()->updateOrCreate(
            ['school_id' => $school->id, 'feature_flag_key' => AutomationFeatureGate::FLAG],
            ['enabled' => $enabled],
        ));
        app(FeatureFlagResolver::class)->forgetCache(AutomationFeatureGate::FLAG, $school);
    }

    private function pendingExecution(School $school): AutomationExecution
    {
        $instance = $this->in($school, fn () => AutomationRuleInstance::query()->where('school_id', $school->id)->firstOrFail());

        return $this->in($school, fn () => AutomationExecution::query()->create([
            'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => (string) Str::uuid(),
            'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
            'subject_id' => (string) Str::uuid(), 'status' => AutomationExecution::STATUS_PENDING,
        ]));
    }

    #[Test]
    public function automation_needs_the_entitlement_even_with_the_flag_on(): void
    {
        $school = app(DemoDataBuilder::class)->build()->school;
        $this->assertTrue(app(AutomationFeatureGate::class)->isEnabledFor($school), 'The demo enables the flag.');
        $this->disable($school, 'automation');

        // The trigger consumer creates nothing; the events are acknowledged.
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(0, $this->in($school, fn () => AutomationExecution::query()->where('school_id', $school->id)->count()));
        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->where('status', 'pending')->count());

        // An execution queued while entitled is skipped for good when it runs.
        $execution = $this->pendingExecution($school);
        $this->in($school, fn () => app(AutomationExecutionService::class)->run($school, $execution->id));
        $skipped = $this->in($school, fn () => $execution->fresh());
        $this->assertSame(AutomationExecution::STATUS_SKIPPED, $skipped->status);
        $this->assertSame('automation_module_not_entitled', $skipped->outcome_code);
        $this->assertSame(0, $this->in($school, fn () => AutomationReviewItem::query()->where('execution_id', $execution->id)->count()));

        SchoolFactory::setOptionalModules($school, true, ['automation']);
        $this->travel(10)->minutes();
        Artisan::call('automation:executions-redispatch');
        $this->assertSame(AutomationExecution::STATUS_SKIPPED, $this->in($school, fn () => $execution->fresh()->status), 'Terminal: nothing replayed.');
    }

    #[Test]
    public function the_entitlement_never_overrides_the_flag(): void
    {
        $school = app(DemoDataBuilder::class)->build()->school;
        $this->setFlag($school, false);
        $this->assertContains('automation', app(SchoolModuleEntitlements::class)->entitledModules($school));

        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(0, $this->in($school, fn () => AutomationExecution::query()->where('school_id', $school->id)->count()));

        $execution = $this->pendingExecution($school);
        $this->in($school, fn () => app(AutomationExecutionService::class)->run($school, $execution->id));
        $this->assertSame('automation_disabled_for_school', $this->in($school, fn () => $execution->fresh()->outcome_code));
    }

    #[Test]
    public function both_enabled_runs_automation_as_before(): void
    {
        $school = app(DemoDataBuilder::class)->build()->school;

        Artisan::call('platform:outbox-dispatch');

        $executions = $this->in($school, fn () => AutomationExecution::query()->where('school_id', $school->id)->get());
        $this->assertCount(2, $executions);
        $this->assertSame([AutomationExecution::STATUS_SUCCEEDED], $executions->pluck('status')->unique()->values()->all());
    }
}
