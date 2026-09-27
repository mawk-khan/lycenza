<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.10 §14-20/§30-35/§50-53 -- the emergency quiet-hours-bypass
 * decision, end to end through App\Domain\Communications\Application\AnnouncementService::publish(),
 * proving the exact precedence chain: channel policy ALLOW -> quiet
 * hours? -> Emergency? -> School/channel bypass enabled? -> SEND_NOW
 * (with reason emergency_quiet_hours_bypass) or DEFER. Every other
 * safety boundary (channel-policy denial, global technical gate,
 * recipient eligibility) is proven to still win regardless of
 * Emergency.
 */
class CommunicationEmergencyTimingTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    /** @return array{creator: User, member: User, school: School} */
    private function setUpSchoolWithMember(bool $quietBypassAllowed = false): array
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, [
            'enabled' => true,
            'quiet_hours_start' => '20:00:00',
            'quiet_hours_end' => '07:00:00',
            'emergency_bypass_allowed' => $quietBypassAllowed,
        ]);

        return ['creator' => $creator, 'member' => $member, 'school' => $school];
    }

    private function emailDeliveryStatus(TenantContext $context, $school, string $messageId): string
    {
        return $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $messageId)->pluck('id'))
            ->where('channel', 'email')
            ->value('status'));
    }

    // --- §50: core combination matrix ----------------------------------

    #[Test]
    public function standard_during_quiet_hours_with_bypass_enabled_still_defers(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('queued', $this->emailDeliveryStatus(app(TenantContext::class), $school, $published->message_id));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function critical_standard_during_quiet_hours_with_bypass_enabled_still_defers(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Critical, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('queued', $this->emailDeliveryStatus(app(TenantContext::class), $school, $published->message_id));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function required_standard_during_quiet_hours_with_bypass_enabled_still_defers(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('queued', $this->emailDeliveryStatus(app(TenantContext::class), $school, $published->message_id));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function emergency_during_quiet_hours_with_bypass_disabled_still_defers(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: false);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('queued', $this->emailDeliveryStatus(app(TenantContext::class), $school, $published->message_id));
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function emergency_during_quiet_hours_with_bypass_enabled_sends_now(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $this->assertSame('sent', $this->emailDeliveryStatus($context, $school, $published->message_id));
        $this->assertEmailAcceptedCount(1);

        $this->assertSame(1, $context->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'communication.emergency_quiet_hours_bypass_used')->where('subject_id', $published->id)->count()));
    }

    #[Test]
    public function emergency_outside_quiet_hours_sends_now_without_a_bypass_reason(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        // 12:00 is well outside the 20:00-07:00 window -- no bypass is
        // even considered, this is an ordinary immediate send.
        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $this->assertSame('sent', $this->emailDeliveryStatus($context, $school, $published->message_id));
        $this->assertEmailAcceptedCount(1);

        $this->assertSame(0, $context->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'communication.emergency_quiet_hours_bypass_used')->where('subject_id', $published->id)->count()));
    }

    // --- §20: IN_APP is never delayed, bypass or not --------------------

    #[Test]
    public function in_app_is_never_delayed_for_an_emergency_announcement_either(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: false);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $inAppStatus = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'in_app')->value('status'));

        $this->assertSame('delivered', $inAppStatus);
    }

    // --- §51: preference is owned by Requirement, not by Emergency ------

    #[Test]
    public function a_disabled_optional_email_preference_never_suppresses_a_required_emergency(): void
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
        // No quiet-hours policy at all -- proves this is purely about
        // preference/requirement precedence, unrelated to timing.
        $this->travelTo(Carbon::parse('2026-08-23 12:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->assertSame('sent', $this->emailDeliveryStatus(app(TenantContext::class), $school, $published->message_id));
        $this->assertEmailAcceptedCount(1);
    }

    // --- §52/§17: channel policy still wins ------------------------------

    #[Test]
    public function school_channel_policy_denial_of_required_email_still_suppresses_an_emergency(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->createChannelPolicy($school, [
            'channel' => 'email',
            'optional_allowed' => true,
            'required_allowed' => false,
            'recipient_can_opt_out' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $emailDeliveryCount = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->count());
        $this->assertSame(0, $emailDeliveryCount, 'Emergency must never override a school channel-policy denial.');

        $reason = $context->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('channel', 'email')->value('reason'));
        $this->assertSame('school_required_channel_disabled', $reason);

        $this->assertNoEmailAccepted();
    }

    // --- §19/§53: the global technical gate still wins -------------------

    #[Test]
    public function a_globally_disabled_email_driver_still_produces_no_real_send_for_an_emergency_bypass(): void
    {
        // Deliberately left FALSE (the safe default) -- proves an
        // Emergency bypass cannot make a technically-disabled driver
        // send for real. School channel policy (unaware of the global
        // technical gate) still ALLOWs the channel and a delivery row
        // IS created -- the driver's own EmailChannelDriver::send()
        // is what refuses, terminally and deterministically, exactly
        // like Channels\EmailChannelDriverTest already proves for a
        // STANDARD delivery.
        Config::set('communications.channels.email.enabled', false);
        $this->fakeEmail();
        ['creator' => $creator, 'school' => $school] = $this->setUpSchoolWithMember(quietBypassAllowed: true);
        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $delivery = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->first());

        $this->assertNotNull($delivery);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('email_channel_disabled', $delivery->failure_code);

        $this->assertNoEmailAccepted();
    }
}
