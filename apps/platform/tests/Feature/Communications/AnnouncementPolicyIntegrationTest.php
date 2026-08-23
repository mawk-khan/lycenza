<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Policy\CommunicationPreferenceService;
use App\Domain\Communications\Application\Policy\SchoolChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §44/§47/§48: the policy engine wired into
 * AnnouncementService::publish() -- ALLOW creates a real
 * CommunicationDelivery, SUPPRESS records a
 * CommunicationDeliveryPolicyDecision and creates no delivery at all
 * (brief §21: suppression is not failure). Mail::fake() throughout
 * (brief §52) -- no real email is ever sent.
 */
class AnnouncementPolicyIntegrationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function publishSchoolWide($school, $creator, array $channels = [CommunicationChannel::InApp, CommunicationChannel::Email], CommunicationRequirement $requirement = CommunicationRequirement::Optional)
    {
        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: $channels, requirement: $requirement,
        );

        return $this->service()->publish($announcement, $creator);
    }

    #[Test]
    public function optional_communication_with_email_preference_disabled_creates_in_app_but_suppresses_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $published = $this->publishSchoolWide($school, $creator);

        $context = app(TenantContext::class);
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->pluck('channel')->all());
        $this->assertSame(['in_app'], $channels);

        $decisions = $context->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->get(['channel', 'reason']));
        $this->assertCount(1, $decisions);
        $this->assertSame('email', $decisions->first()->channel);
        $this->assertSame('recipient_preference_disabled', $decisions->first()->reason);

        Mail::assertNothingSent();
    }

    #[Test]
    public function required_communication_bypasses_a_disabled_email_preference_when_school_policy_permits(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $published = $this->publishSchoolWide($school, $creator, requirement: CommunicationRequirement::Required);

        $context = app(TenantContext::class);
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->pluck('channel')->sort()->values()->all());

        $this->assertSame(['email', 'in_app'], $channels);
        Mail::assertSentCount(1);
    }

    #[Test]
    public function required_communication_is_still_suppressed_when_school_required_policy_denies_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        app(SchoolChannelPolicyService::class)->setPolicy($school, $creator, CommunicationChannel::Email, true, false, true);

        $published = $this->publishSchoolWide($school, $creator, requirement: CommunicationRequirement::Required);

        $context = app(TenantContext::class);
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->pluck('channel')->all());

        $this->assertSame(['in_app'], $channels);
        Mail::assertNothingSent();
    }

    #[Test]
    public function three_recipients_with_different_states_are_each_handled_distinctly(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $memberA = $this->createUser(['email' => 'a@school-os.test']); // email enabled (default)
        $this->createMembership($memberA, $school);

        $memberB = $this->createUser(['email' => 'b@school-os.test']);
        $membershipB = $this->createMembership($memberB, $school);
        $this->createPreference($membershipB, ['channel' => 'email', 'preference' => 'disabled']); // policy-suppressed

        $memberC = $this->createUser(['email' => 'not-a-valid-email']); // no usable address
        $this->createMembership($memberC, $school);

        $published = $this->publishSchoolWide($school, $creator);

        $context = app(TenantContext::class);
        $emailDeliveries = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->join('communication_recipients', 'communication_recipients.id', '=', 'communication_deliveries.recipient_id')
            ->where('communication_recipients.message_id', $published->message_id)
            ->where('communication_deliveries.channel', 'email')
            ->get(['communication_recipients.recipient_user_id', 'communication_deliveries.status', 'communication_deliveries.failure_code']));

        $byUser = $emailDeliveries->keyBy('recipient_user_id');

        // A: a real email delivery was attempted and succeeded.
        $this->assertSame('sent', $byUser->get($memberA->id)->status);
        // C: a real email delivery was attempted (policy allowed it) but
        // failed at the driver layer -- a DIFFERENT outcome than B.
        $this->assertSame('failed', $byUser->get($memberC->id)->status);
        $this->assertSame('recipient_email_missing', $byUser->get($memberC->id)->failure_code);
        // B: policy suppressed it -- no delivery row exists for B at all.
        $this->assertFalse($byUser->has($memberB->id));

        $decisions = $context->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('channel', 'email')->get());
        $this->assertCount(1, $decisions);
        $this->assertSame($memberB->id, $decisions->first()->recipient_user_id);
        $this->assertSame('recipient_preference_disabled', $decisions->first()->reason);
    }

    #[Test]
    public function scenario_a_disabling_a_preference_after_publish_does_not_change_the_historical_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'enabled']);

        $published = $this->publishSchoolWide($school, $creator);

        $context = app(TenantContext::class);
        $emailDeliveryBefore = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->first());
        $this->assertSame('sent', $emailDeliveryBefore->status);

        // Preference disabled AFTER publish.
        app(CommunicationPreferenceService::class)->setPreference($membership, $member, CommunicationChannel::Email, false);

        $emailDeliveryAfter = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->first());

        $this->assertSame($emailDeliveryBefore->id, $emailDeliveryAfter->id);
        $this->assertSame('sent', $emailDeliveryAfter->status, 'A historical delivery must never be rewritten by a later preference change.');
    }

    #[Test]
    public function scenario_b_enabling_a_preference_after_a_suppressed_publish_does_not_retroactively_send_the_old_message(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $published = $this->publishSchoolWide($school, $creator);

        $context = app(TenantContext::class);
        $deliveryCountBefore = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->count());
        $this->assertSame(0, $deliveryCountBefore);

        // Preference re-enabled AFTER the (suppressed) publish.
        app(CommunicationPreferenceService::class)->setPreference($membership, $member, CommunicationChannel::Email, true);

        $deliveryCountAfter = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->count());

        $this->assertSame(0, $deliveryCountAfter, 'Re-enabling a preference must never retroactively create a delivery for an already-published message.');
        Mail::assertNothingSent();
    }
}
