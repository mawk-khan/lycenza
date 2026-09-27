<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.9 §27/§28/§46/§52-56/§59/§60 -- end-to-end proofs that quiet
 * hours delay EMAIL transport without delaying the canonical
 * publication/IN_APP record, and that neither REQUIRED nor CRITICAL
 * silently bypass the delay.
 */
class AnnouncementDeliveryTimingTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function deliveriesByChannel(TenantContext $context, $school, string $messageId)
    {
        return $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $messageId)->pluck('id'))
            ->get()->keyBy('channel'));
    }

    #[Test]
    public function manual_publish_during_quiet_hours_publishes_immediately_and_only_defers_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);

        $context = app(TenantContext::class);
        $deliveries = $this->deliveriesByChannel($context, $school, $published->message_id);

        $this->assertSame('delivered', $deliveries['in_app']->status);
        $this->assertSame('queued', $deliveries['email']->status);
        $this->assertSame(0, $deliveries['email']->attempts);
        $this->assertTrue($deliveries['email']->next_attempt_at->isFuture());
        $this->assertTrue($deliveries['email']->next_attempt_at->equalTo(Carbon::parse('2026-08-24 07:00:00', 'Asia/Kolkata')));

        $attemptCount = $context->withSchool($school, fn () => CommunicationDeliveryAttempt::query()
            ->where('communication_delivery_id', $deliveries['email']->id)->count());
        $this->assertSame(0, $attemptCount, 'A deferred delivery must not have a fake attempt recorded.');

        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function scheduled_publication_inside_quiet_hours_defers_email_and_redispatch_sends_it_exactly_once(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $this->travelTo(Carbon::parse('2026-08-23 20:55:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addSeconds(1));

        $this->travel(2)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame('published', $fresh->status);

        $deliveries = $this->deliveriesByChannel($context, $school, $fresh->message_id);
        $this->assertSame('delivered', $deliveries['in_app']->status);
        $this->assertSame('queued', $deliveries['email']->status);

        $this->assertNoEmailAccepted();

        // Move past quiet-hours end and let the existing redispatch
        // pipeline pick it up, unmodified -- no quiet-hours-specific
        // scheduler was introduced (brief §24).
        $this->travelTo(Carbon::parse('2026-08-24 07:00:01', 'Asia/Kolkata'));
        $this->artisan('platform:communication-deliveries-redispatch')->assertExitCode(0);

        $this->assertEmailAcceptedCount(1);

        // Idempotency (brief §26): running redispatch again must not
        // send a second time.
        $this->artisan('platform:communication-deliveries-redispatch')->assertExitCode(0);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function optional_email_disabled_by_preference_is_suppressed_before_timing_is_ever_considered(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59']);

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Optional,
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);

        $emailDeliveryCount = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->count());
        $this->assertSame(0, $emailDeliveryCount, 'A preference-suppressed channel must never get a delivery row, deferred or otherwise.');

        $reason = $context->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_user_id', $member->id)->where('channel', 'email')
            ->value('reason'));
        $this->assertSame('recipient_preference_disabled', $reason);
    }

    #[Test]
    public function a_required_communication_still_defers_email_during_quiet_hours(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        // Optional preference disabled -- irrelevant for a Required
        // communication (5A.5 precedence: Required never consults it).
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59']);

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $deliveries = $this->deliveriesByChannel($context, $school, $published->message_id);

        $this->assertArrayHasKey('email', $deliveries, 'Required bypasses the preference opt-out, so a delivery row must exist.');
        $this->assertSame('queued', $deliveries['email']->status, 'Required does NOT bypass quiet-hours timing.');
        $this->assertNotNull($deliveries['email']->next_attempt_at);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function critical_priority_does_not_bypass_quiet_hours(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59']);

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Critical, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $deliveries = $this->deliveriesByChannel($context, $school, $published->message_id);

        $this->assertSame('queued', $deliveries['email']->status, 'CRITICAL priority must not silently become an emergency bypass.');
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function a_disabled_timing_policy_matches_pre_5a9_immediate_send_behavior(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        // No timing policy row at all -- brief §51's core regression
        // invariant.

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $deliveries = $this->deliveriesByChannel($context, $school, $published->message_id);

        $this->assertSame('sent', $deliveries['email']->status);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function a_multi_school_users_email_is_deferred_for_one_school_and_immediate_for_another_at_the_same_moment(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $sharedUser = $this->createUser();

        $schoolA = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creatorA = $this->createUser();
        $creatorAMembership = $this->createMembership($creatorA, $schoolA);
        $this->assignSchoolRole($creatorAMembership, 'school_admin');
        $this->createMembership($sharedUser, $schoolA);
        $this->createDeliveryTimingPolicy($schoolA, ['enabled' => true, 'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59']);

        $schoolB = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creatorB = $this->createUser();
        $creatorBMembership = $this->createMembership($creatorB, $schoolB);
        $this->assignSchoolRole($creatorBMembership, 'school_admin');
        $this->createMembership($sharedUser, $schoolB);
        // No timing policy for School B -- immediate send.

        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcementA = $this->service()->createDraft(
            $schoolA, $creatorA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $publishedA = $this->service()->publish($announcementA, $creatorA);

        $announcementB = $this->service()->createDraft(
            $schoolB, $creatorB, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $publishedB = $this->service()->publish($announcementB, $creatorB);

        $context = app(TenantContext::class);
        $deliveriesA = $this->deliveriesByChannel($context, $schoolA, $publishedA->message_id);
        $deliveriesB = $this->deliveriesByChannel($context, $schoolB, $publishedB->message_id);

        $this->assertSame('queued', $deliveriesA['email']->status, 'School A email deferred by its own quiet-hours policy.');
        $this->assertSame('sent', $deliveriesB['email']->status, 'School B has no quiet-hours policy -- unaffected by School A.');
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function timing_policy_lookup_is_not_repeated_once_per_recipient(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        for ($i = 0; $i < 30; $i++) {
            $this->createMembership($this->createUser(), $school);
        }
        $this->createDeliveryTimingPolicy($school, ['enabled' => true, 'quiet_hours_start' => '20:00:00', 'quiet_hours_end' => '07:00:00']);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );

        DB::connection('pgsql')->flushQueryLog();
        DB::connection('pgsql')->enableQueryLog();

        $this->service()->publish($announcement, $creator);

        $log = DB::connection('pgsql')->getQueryLog();
        DB::connection('pgsql')->disableQueryLog();

        $timingPolicyQueries = collect($log)
            ->filter(fn ($entry) => str_contains($entry['query'], 'communication_delivery_timing_policies'))
            ->count();

        // Exactly one lookup for the one requested channel (email) that
        // is ever timing-evaluated -- never once per recipient (brief
        // §60), regardless of the 30 recipients above.
        $this->assertSame(1, $timingPolicyQueries);
    }
}
