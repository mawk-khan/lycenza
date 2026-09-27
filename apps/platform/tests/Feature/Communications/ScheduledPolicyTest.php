<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Policy\CommunicationPreferenceService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.5 §23/§45 (mandatory): proves policy/preference evaluation
 * happens at DUE-PUBLICATION time, not at schedule-creation time --
 * both directions.
 */
class ScheduledPolicyTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    #[Test]
    public function a_preference_disabled_after_scheduling_but_before_due_time_suppresses_email_at_publication(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'enabled']);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addSeconds(1));

        // Disabled AFTER scheduling, BEFORE the schedule is due.
        app(CommunicationPreferenceService::class)->setPreference($membership, $member, CommunicationChannel::Email, false);

        $this->travel(2)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $fresh->message_id)->pluck('id'))
            ->pluck('channel')->all());

        $this->assertSame(['in_app'], $channels, 'The DUE-TIME preference (disabled) must govern, not the schedule-time one (enabled).');
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function a_preference_enabled_after_scheduling_but_before_due_time_permits_email_at_publication(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership, ['channel' => 'email', 'preference' => 'disabled']);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $scheduled = $this->service()->schedule($announcement, $creator, now()->addSeconds(1));

        // Enabled AFTER scheduling, BEFORE the schedule is due.
        app(CommunicationPreferenceService::class)->setPreference($membership, $member, CommunicationChannel::Email, true);

        $this->travel(2)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($school, fn () => $scheduled->fresh());
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $fresh->message_id)->pluck('id'))
            ->pluck('channel')->sort()->values()->all());

        $this->assertSame(['email', 'in_app'], $channels, 'The DUE-TIME preference (enabled) must govern, not the schedule-time one (disabled).');
        $this->assertEmailAcceptedCount(1);
    }
}
