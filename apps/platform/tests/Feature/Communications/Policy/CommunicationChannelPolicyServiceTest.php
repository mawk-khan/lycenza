<?php

namespace Tests\Feature\Communications\Policy;

use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Application\Policy\CommunicationPolicyReason;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationRequirement;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §43: the policy engine's precedence rules, in isolation
 * -- every scenario the brief explicitly lists.
 */
class CommunicationChannelPolicyServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function service(): CommunicationChannelPolicyService
    {
        return app(CommunicationChannelPolicyService::class);
    }

    #[Test]
    public function in_app_is_always_canonically_allowed_for_an_eligible_recipient(): void
    {
        $school = $this->createSchool();
        $decision = $this->service()->evaluate($school, 'some-membership-id', CommunicationChannel::InApp, CommunicationRequirement::Optional);

        $this->assertTrue($decision->allowed);
        $this->assertSame(CommunicationPolicyReason::CanonicalInApp, $decision->reason);
    }

    #[Test]
    public function in_app_is_suppressed_for_an_ineligible_recipient(): void
    {
        $school = $this->createSchool();
        $decision = $this->service()->evaluate($school, null, CommunicationChannel::InApp, CommunicationRequirement::Optional);

        $this->assertFalse($decision->allowed);
        $this->assertSame(CommunicationPolicyReason::RecipientIneligible, $decision->reason);
    }

    #[Test]
    public function an_unsupported_channel_is_suppressed(): void
    {
        $school = $this->createSchool();
        $decision = $this->service()->evaluate($school, 'some-membership-id', CommunicationChannel::Sms, CommunicationRequirement::Optional);

        $this->assertFalse($decision->allowed);
        $this->assertSame(CommunicationPolicyReason::UnsupportedChannel, $decision->reason);
    }

    #[Test]
    public function optional_email_with_no_school_override_and_no_preference_is_allowed_by_default(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);

        $decision = $this->service()->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Optional);

        $this->assertTrue($decision->allowed);
    }

    #[Test]
    public function optional_email_with_preference_explicitly_enabled_is_allowed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'enabled']);

        $service = $this->service();
        $service->preloadPreferences($school, [$membership->id], CommunicationChannel::Email);
        $decision = $service->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Optional);

        $this->assertTrue($decision->allowed);
    }

    #[Test]
    public function optional_email_with_preference_disabled_is_suppressed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $service = $this->service();
        $service->preloadPreferences($school, [$membership->id], CommunicationChannel::Email);
        $decision = $service->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Optional);

        $this->assertFalse($decision->allowed);
        $this->assertSame(CommunicationPolicyReason::RecipientPreferenceDisabled, $decision->reason);
    }

    #[Test]
    public function optional_email_is_suppressed_when_school_optional_policy_disallows_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createChannelPolicy($school, ['channel' => 'email', 'optional_allowed' => false, 'required_allowed' => true, 'recipient_can_opt_out' => true]);

        $decision = $this->service()->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Optional);

        $this->assertFalse($decision->allowed);
        $this->assertSame(CommunicationPolicyReason::SchoolOptionalChannelDisabled, $decision->reason);
    }

    #[Test]
    public function required_email_bypasses_a_disabled_recipient_preference_when_school_policy_permits_required(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $service = $this->service();
        $service->preloadPreferences($school, [$membership->id], CommunicationChannel::Email);
        $decision = $service->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Required);

        $this->assertTrue($decision->allowed, 'Required communication must not be suppressed by an opt-out preference.');
    }

    #[Test]
    public function required_email_is_still_suppressed_when_school_required_policy_denies_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createChannelPolicy($school, ['channel' => 'email', 'optional_allowed' => true, 'required_allowed' => false, 'recipient_can_opt_out' => true]);

        $decision = $this->service()->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Required);

        $this->assertFalse($decision->allowed, 'Required=true must never bypass the schools own required_allowed=false.');
        $this->assertSame(CommunicationPolicyReason::SchoolRequiredChannelDisabled, $decision->reason);
    }

    #[Test]
    public function optional_email_ignores_recipient_preference_when_school_disallows_opt_out(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);
        $this->createChannelPolicy($school, ['channel' => 'email', 'optional_allowed' => true, 'required_allowed' => true, 'recipient_can_opt_out' => false]);

        $service = $this->service();
        $service->preloadPreferences($school, [$membership->id], CommunicationChannel::Email);
        $decision = $service->evaluate($school, $membership->id, CommunicationChannel::Email, CommunicationRequirement::Optional);

        $this->assertTrue($decision->allowed, 'recipient_can_opt_out=false must mean the preference is never consulted.');
    }
}
