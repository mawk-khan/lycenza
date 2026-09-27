<?php

namespace Tests\Feature\Platform\Schools;

use App\Domain\Automation\Application\AutomationExecutionService;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationExecutionAttempt;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\CommunicationDeliveryFactory;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\DomainEventOutbox;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Ai\AiContextTokenService;
use App\Support\Ai\AiGatewayAuthorizationException;
use App\Support\Ai\AiGatewayClient;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSubscriptionService;
use Database\Seeders\Demo\DemoDataBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Concerns\SignsServiceAssertions;
use Tests\TestCase;

/**
 * Phase 0N.9 (ADR 0047 sections 8-10): what a suspended School stops, at
 * EXECUTION time, per substrate -- and what continues, and what RESUME
 * does (no replay). Suspension here is the raw permitted transition, the
 * same row change SchoolLifecycleService makes (proven there); the point
 * is that nothing downstream trusts state from when the work was queued.
 */
class SchoolSuspensionEnforcementTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail, SignsServiceAssertions;

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function suspend(School $school): void
    {
        $school->update(['status' => 'suspended']);
    }

    private function resume(School $school): void
    {
        $school->update(['status' => 'active']);
    }

    // --- Communications -------------------------------------------------------------

    private function pendingDelivery(): array
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $delivery = $this->createDelivery($this->createRecipient($message, $recipientUser));

        return [$school, $delivery];
    }

    private function runDelivery(School $school, CommunicationDelivery $delivery): void
    {
        (new ProcessCommunicationDeliveryJob($school->id, $delivery->id))->handle(
            app(CommunicationChannelRegistry::class),
            app(TenantContext::class),
            app(CommunicationDeliveryTimingPolicyService::class),
        );
    }

    #[Test]
    public function a_communication_delivery_is_deferred_without_an_attempt_and_resumes_naturally(): void
    {
        [$school, $delivery] = $this->pendingDelivery();
        $this->suspend($school);

        $this->runDelivery($school, $delivery);

        $held = $this->in($school, fn () => $delivery->fresh());
        $this->assertSame('queued', $held->status);
        $this->assertSame(0, $held->attempts, 'Suspension never consumes a delivery attempt.');
        $this->assertNotNull($held->next_attempt_at);
        $this->assertNull($held->processing_lease_expires_at);
        $this->assertNull($held->failure_code);
        $this->assertSame(0, $this->in($school, fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count()));

        // The redispatcher skips the School while it is suspended: no loop.
        $this->travel(1)->minutes();
        Artisan::call('platform:communication-deliveries-redispatch');
        $this->assertSame('queued', $this->in($school, fn () => $delivery->fresh()->status));

        // After RESUME the normal redispatcher sends it -- nothing replayed.
        $this->resume($school);
        Artisan::call('platform:communication-deliveries-redispatch');
        $sent = $this->in($school, fn () => $delivery->fresh());
        $this->assertSame('delivered', $sent->status);
        $this->assertSame(1, $sent->attempts);
    }

    #[Test]
    public function no_new_delivery_is_created_for_a_suspended_school(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $recipient = $this->createRecipient($message, $sender);
        $this->suspend($school);

        $this->expectException(SchoolNotOperationalException::class);

        try {
            $this->in($school, fn () => app(CommunicationDeliveryFactory::class)->createInAppDelivery($recipient));
        } finally {
            $this->assertSame(0, $this->in($school, fn () => CommunicationDelivery::query()->where('recipient_id', $recipient->id)->count()));
        }
    }

    #[Test]
    public function a_scheduled_announcement_is_held_while_suspended_and_published_after_resume(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $service = app(AnnouncementService::class);
        $draft = $service->createDraft($school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide);
        $scheduled = $service->schedule($draft, $creator, now()->addMinute());
        $scheduledAt = $scheduled->scheduled_at->toIso8601String();

        $this->suspend($school);
        $this->travel(2)->minutes();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $held = $this->in($school, fn () => $scheduled->fresh());
        $this->assertSame('scheduled', $held->status);
        $this->assertSame($scheduledAt, $held->scheduled_at->toIso8601String(), 'Held, not backed off.');

        $this->resume($school);
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);
        $this->assertSame('published', $this->in($school, fn () => $scheduled->fresh()->status));
    }

    // --- Webhooks ---------------------------------------------------------------------

    private function subscribedEndpoint(School $school, User $user): WebhookEndpoint
    {
        return $this->in($school, function () use ($school, $user) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id,
                'name' => 'test',
                'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret',
                'status' => 'active',
            ]);
            app(WebhookSubscriptionService::class)->subscribe($endpoint, 'school.setting.changed.v1', $user);

            return $endpoint;
        });
    }

    #[Test]
    public function a_webhook_is_retained_but_never_sent_while_suspended_and_delivered_after_resume(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $endpoint = $this->subscribedEndpoint($school, $user);

        // The event happened while the School was active; the delivery
        // runs after it was suspended.
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $this->suspend($school);
        Artisan::call('platform:outbox-dispatch');

        $delivery = $this->in($school, fn () => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->firstOrFail());
        $this->assertSame('retrying', $delivery->status, 'Evidence kept, deferred with the existing retry state.');
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame(0, $this->in($school, fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count()));
        Http::assertNothingSent();

        $this->travel(1)->minutes();
        Artisan::call('platform:webhook-deliveries-redispatch');
        Http::assertNothingSent();

        $this->resume($school);
        Artisan::call('platform:webhook-deliveries-redispatch');
        $this->assertSame('delivered', $this->in($school, fn () => $delivery->fresh()->status));
        Http::assertSentCount(1);
    }

    // --- Automation ---------------------------------------------------------------------

    #[Test]
    public function automation_creates_nothing_while_suspended_and_an_execution_reaching_run_time_is_skipped_for_good(): void
    {
        $w = app(DemoDataBuilder::class)->build();
        $school = $w->school;
        $this->suspend($school);

        $this->assertGreaterThan(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->where('status', 'pending')->count());
        Artisan::call('platform:outbox-dispatch');
        $this->assertSame(0, $this->in($school, fn () => AutomationExecution::query()->where('school_id', $school->id)->count()));
        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->where('status', 'pending')->count(), 'Outbox dispatch continues; evidence kept.');

        // An execution queued before the suspension reaches run time now.
        $instance = $this->in($school, fn () => AutomationRuleInstance::query()->where('school_id', $school->id)->firstOrFail());
        $execution = $this->in($school, fn () => AutomationExecution::query()->create([
            'school_id' => $school->id,
            'rule_instance_id' => $instance->id,
            'trigger_key' => (string) Str::uuid(),
            'trigger_event_type' => 'academic_year.activated.v1',
            'subject_type' => 'academic_year',
            'subject_id' => (string) Str::uuid(),
            'status' => AutomationExecution::STATUS_PENDING,
        ]));

        $this->in($school, fn () => app(AutomationExecutionService::class)->run($school, $execution->id));

        $skipped = $this->in($school, fn () => $execution->fresh());
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('school_suspended', $skipped->outcome_code);
        $this->assertSame(0, $this->in($school, fn () => AutomationReviewItem::query()->where('execution_id', $execution->id)->count()));
        $this->assertSame('skipped', $this->in($school, fn () => AutomationExecutionAttempt::query()->where('execution_id', $execution->id)->value('outcome')));

        // RESUME replays nothing: the skipped execution stays terminal.
        $this->resume($school);
        $this->travel(10)->minutes();
        Artisan::call('automation:executions-redispatch');
        $this->assertSame('skipped', $this->in($school, fn () => $execution->fresh()->status));
        $this->assertSame(0, $this->in($school, fn () => AutomationReviewItem::query()->where('execution_id', $execution->id)->count()));
    }

    // --- AI ------------------------------------------------------------------------------

    #[Test]
    public function no_ai_context_token_is_minted_for_a_school_that_is_not_active(): void
    {
        Http::fake();
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->suspend($school);

        foreach ([
            fn () => app(AiGatewayClient::class)->invokeTool($user, $school, 'school.settings.view', 'phase0b-proof-agent', 'school.echo'),
            fn () => app(AiGatewayClient::class)->complete($user, $school, 'school.settings.view', 'phase0b-proof-agent', 'hello'),
        ] as $call) {
            try {
                $call();
                $this->fail('No AI context may be minted for a non-active School.');
            } catch (AiGatewayAuthorizationException $e) {
                $this->assertStringContainsString('is not active', $e->getMessage());
            }
        }

        foreach (['provisioning', 'archived'] as $status) {
            $other = $this->createSchool(['status' => $status]);
            $member = $this->createUserWithCapabilities($other, ['school.settings.view']);
            try {
                app(AiGatewayClient::class)->invokeTool($member, $other, 'school.settings.view', 'phase0b-proof-agent', 'school.echo');
                $this->fail("No AI context may be minted for a {$status} School.");
            } catch (AiGatewayAuthorizationException $e) {
                $this->assertStringContainsString('is not active', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_minted_while_active_is_refused_by_every_internal_ai_endpoint_after_suspension(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = app(AiContextTokenService::class)->issue($school, $user, ['school.settings.view']);
        $this->suspend($school);
        $gatewayKey = $this->serviceKey('test-ai-gateway-1');
        $this->useServiceKeys([$this->publicJwk($gatewayKey)]);
        $this->asService($this->gatewaySigner($gatewayKey));

        $this->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token])
            ->assertForbidden()->assertJsonPath('error.code', 'school_unavailable')->assertJsonMissing(['schoolName' => $school->name]);
        $this->postJson('/api/internal/ai/completions/authorize', ['context_token' => $token, 'capability' => 'school.settings.view'])
            ->assertForbidden()->assertJsonPath('error.code', 'school_unavailable');

        $this->assertSame(0, $this->in($school, fn () => DomainEventOutbox::query()->count() + SchoolAuditEvent::query()->where('event_type', 'ai.tool_invoked')->count()));

        // Resumed: the same (still valid) token works again -- nothing cached.
        $this->resume($school);
        $this->postJson('/api/internal/ai/tools/school-echo', ['context_token' => $token])->assertOk();
    }

    // --- Guardian invitation acceptance ----------------------------------------------------

    #[Test]
    public function a_guardian_invitation_into_a_suspended_school_is_unusable_and_left_intact(): void
    {
        $this->fakeEmail();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'held.guardian@example.com');
        app(AccountInvitationService::class)->invite($school, $guardian, $admin);
        preg_match('#/invitations/[^\s<"]+#', $this->lastAcceptedEmail()->text, $m);
        $path = $m[0];

        $this->suspend($school);

        $this->get($path)->assertInertia(fn (AssertableInertia $p) => $p->where('valid', false));
        $this->post($path, ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])->assertSessionHasErrors('invitation');
        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'held.guardian@example.com')->count());
        $this->assertSame(['pending'], $this->in($school, fn () => GuardianAccountInvitation::query()->where('guardian_id', $guardian->id)->pluck('status')->all()));

        // Resumed: the same link works.
        $this->resume($school);
        $this->post($path, ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])->assertRedirect('/app');
        $this->assertSame(1, SchoolMembership::query()->where('school_id', $school->id)->whereHas('user', fn ($q) => $q->where('email', 'held.guardian@example.com'))->count());
    }

    // --- Outbox consumers and safety work --------------------------------------------------

    #[Test]
    public function outbox_dispatch_continues_but_the_in_app_notice_consumer_adds_nothing(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $this->suspend($school);

        Artisan::call('platform:outbox-dispatch');

        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('status', 'pending')->count());
        $this->assertSame(0, Notification::query()->where('recipient_user_id', $user->id)->count());
    }

    #[Test]
    public function platform_safety_work_continues_for_a_suspended_school(): void
    {
        $root = $this->createPlatformRoot();
        [$member, $school] = $this->createSchoolAdmin('school_admin');
        $elevation = SchoolElevation::query()->create([
            'actor_user_id' => $root->id, 'school_id' => $school->id, 'authority_type' => 'platform',
            'reason_code' => 'operational_support', 'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        $this->suspend($school);

        // Elevation expiry still sweeps.
        $this->travel(31)->minutes();
        Artisan::call('platform:expire-school-elevations');
        $this->assertSame('expired', $elevation->fresh()->status);

        // Pruning still runs; the scheduler heartbeat still records.
        $this->assertSame(0, Artisan::call('platform:webhook-deliveries-prune'));
        $this->assertSame(0, Artisan::call('platform:idempotency-prune'));

        // Sign-in and the context-neutral landing still work.
        $this->actingAs($member)->get('/app')->assertOk();
        $this->get('/app/account/security')->assertOk();
    }

    // --- Web and API -------------------------------------------------------------------------

    #[Test]
    public function the_next_request_after_suspension_clears_the_selection_and_resume_does_not_restore_it(): void
    {
        [$member, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($member)->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->get('/app/settings')->assertOk();

        $this->suspend($school);

        $this->get('/app/settings')->assertRedirect('/app');
        $this->assertNull(session('active_school_id'));
        $this->getJson('/app/settings')->assertStatus(409);
        $this->post("/app/schools/{$school->id}/activate")->assertSessionHasErrors('school');

        $this->resume($school);
        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('activeSchool', null));
        $this->get('/app/settings')->assertRedirect('/app');
        $this->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->get('/app/settings')->assertOk();
    }

    #[Test]
    public function the_api_answers_404_for_every_non_active_status_and_200_when_active(): void
    {
        foreach (['active' => 200, 'provisioning' => 404, 'suspended' => 404, 'archived' => 404] as $status => $expected) {
            $school = $this->createSchool(['status' => $status]);
            $user = $this->createUserWithCapabilities($school, ['school.profile.view']);
            $token = $user->createToken('device')->plainTextToken;

            $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}/context")->assertStatus($expected);
            $profile = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}")->assertStatus($expected);

            if ($expected === 404) {
                $this->assertSame('Not found.', $profile->json('error.message'));
                $this->assertStringNotContainsString($status, $profile->getContent());
            }
        }
    }
}
