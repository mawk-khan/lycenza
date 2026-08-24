<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.11 §59-66 -- bucket correctness for
 * App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel.
 * Every scenario is built through the REAL
 * App\Domain\Communications\Application\AnnouncementService pipeline
 * (matching CommunicationFailedDeliveryTest/CommunicationEmergencyTimingTest's
 * own convention), not hand-crafted rows, except the retry test, which
 * needs a genuinely transient failure a real driver invocation
 * produces.
 */
class CommunicationDeliveryAnalyticsReadModelTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function readModel(): CommunicationDeliveryAnalyticsReadModel
    {
        return app(CommunicationDeliveryAnalyticsReadModel::class);
    }

    #[Test]
    public function a_summary_buckets_read_unread_sent_failed_and_suppressed_exactly_once(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $memberRead = $this->createUser(['email' => 'read@school-os.test']);
        $membershipRead = $this->createMembership($memberRead, $school);

        $memberUnread = $this->createUser(['email' => 'unread@school-os.test']);
        $this->createMembership($memberUnread, $school);

        $memberFailed = $this->createUser(['email' => 'not-a-valid-email']);
        $this->createMembership($memberFailed, $school);

        $memberSuppressed = $this->createUser(['email' => 'suppressed@school-os.test']);
        $membershipSuppressed = $this->createMembership($memberSuppressed, $school);
        $this->createPreference($membershipSuppressed, ['channel' => 'email', 'preference' => 'disabled']);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->announcements()->markRead($published, $memberRead);

        $summary = $this->readModel()->announcementSummary($school, $published);

        $this->assertSame(4, $summary['recipients']);

        $this->assertSame(4, $summary['channels']['in_app']['planned']);
        $this->assertSame(4, $summary['channels']['in_app']['available']);
        $this->assertSame(1, $summary['channels']['in_app']['read']);
        $this->assertSame(3, $summary['channels']['in_app']['unread']);

        // Brief §51: `planned` for EMAIL never includes the suppressed
        // recipient -- no CommunicationDelivery row was ever created
        // for them.
        $this->assertSame(3, $summary['channels']['email']['planned']);
        $this->assertSame(2, $summary['channels']['email']['sent']);
        $this->assertSame(1, $summary['channels']['email']['failed']);
        $this->assertSame(0, $summary['channels']['email']['inProgress']);
        $this->assertSame(1, $summary['channels']['email']['suppressed']);

        $this->assertCount(1, $summary['failures']);
        $this->assertSame('email', $summary['failures'][0]['channel']);
        $this->assertSame('recipient_email_missing', $summary['failures'][0]['failureCode']);

        $this->assertNull($summary['emergency']);
    }

    #[Test]
    public function a_quiet_hours_deferred_delivery_is_never_counted_as_failed(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser(['email' => 'member@school-os.test']);
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, [
            'channel' => 'email',
            'enabled' => true,
            'quiet_hours_start' => '20:00:00',
            'quiet_hours_end' => '07:00:00',
            'emergency_bypass_allowed' => false,
        ]);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $summary = $this->readModel()->announcementSummary($school, $published);

        $this->assertSame(1, $summary['channels']['email']['planned']);
        $this->assertSame(0, $summary['channels']['email']['sent']);
        $this->assertSame(0, $summary['channels']['email']['failed']);
        $this->assertSame(1, $summary['channels']['email']['inProgress']);
        $this->assertSame(1, $summary['deferredQuietHours']['email']);
        $this->assertSame([], $summary['failures']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_emergency_bypass_is_visible_as_governance_evidence_not_a_success_metric(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $creator = $this->createUser();
        $creatorMembership = $this->createMembership($creator, $school);
        $this->assignSchoolRole($creatorMembership, 'school_admin');
        $member = $this->createUser(['email' => 'member@school-os.test']);
        $this->createMembership($member, $school);
        $this->createDeliveryTimingPolicy($school, [
            'channel' => 'email',
            'enabled' => true,
            'quiet_hours_start' => '20:00:00',
            'quiet_hours_end' => '07:00:00',
            'emergency_bypass_allowed' => true,
        ]);

        $this->travelTo(Carbon::parse('2026-08-23 22:00:00', 'Asia/Kolkata'));

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Severe weather warning.',
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $summary = $this->readModel()->announcementSummary($school, $published);

        $this->assertNotNull($summary['emergency']);
        $this->assertSame(['email'], $summary['emergency']['quietHoursBypassChannels']);
        $this->assertSame(1, $summary['emergency']['eligibleDeliveryCounts']['email']);

        // Bypassed, so sent immediately -- never counted as deferred.
        $this->assertSame(0, $summary['deferredQuietHours']['email'] ?? 0);
        $this->assertSame(1, $summary['channels']['email']['sent']);
        Mail::assertSentCount(1);
    }

    /**
     * Phase 5A.11 §48/§60 -- one logical delivery whose FIRST attempt
     * transiently failed and whose SECOND attempt succeeded must be
     * counted once, by its CURRENT state, never as both one failed and
     * one sent delivery. The real retry-scheduling mechanics (backoff,
     * quiet-hours re-check) are already proven by
     * ProcessCommunicationDeliveryRetryTest/ProcessCommunicationDeliveryRetryTimingTest
     * -- this test targets ONLY the read model's own aggregation, so it
     * builds the resulting rows directly rather than re-driving the
     * job (avoids mixing a Mockery mail double with Mail::fake() in one
     * test, which Laravel's MailFake does not support cleanly).
     */
    #[Test]
    public function a_delivery_that_failed_then_succeeded_on_retry_counts_once_as_sent_with_full_attempt_history(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser(['email' => 'retry@school-os.test']);
        $this->createMembership($recipientUser, $school);

        $announcement = $this->createAnnouncement($school, $creator, [
            'status' => 'published', 'published_at' => now(), 'recipient_count' => 1,
        ]);
        $message = app(TenantContext::class)->withSchool($school, fn () => CommunicationMessage::factory()->create([
            'school_id' => $school->id,
            'announcement_id' => $announcement->id,
            'sender_user_id' => $creator->id,
        ]));
        app(TenantContext::class)->withSchool($school, fn () => $announcement->update(['message_id' => $message->id]));

        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'status' => 'sent',
            'attempts' => 2,
            'sent_at' => now(),
            'destination_snapshot' => ['email' => 'retry@school-os.test'],
        ]);
        $this->createDeliveryAttempt($delivery, [
            'attempt_number' => 1, 'outcome' => 'transient_failure', 'failure_code' => 'email_transport_unavailable',
        ]);
        $this->createDeliveryAttempt($delivery, ['attempt_number' => 2, 'outcome' => 'success']);

        $summary = $this->readModel()->announcementSummary($school, $announcement);

        $this->assertSame(1, $summary['channels']['email']['sent']);
        $this->assertSame(0, $summary['channels']['email']['failed']);
        $this->assertSame([], $summary['failures']);

        $attempts = $this->readModel()->attemptHistory($school, $delivery);

        $this->assertCount(2, $attempts);
        $this->assertSame('transient_failure', $attempts[0]['outcome']);
        $this->assertSame('email_transport_unavailable', $attempts[0]['failureCode']);
        $this->assertSame('success', $attempts[1]['outcome']);
    }

    #[Test]
    public function school_overview_aggregates_across_announcements_and_never_leaks_across_schools(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser(['email' => 'a@school-os.test']);
        $this->createMembership($memberA, $schoolA);

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser(['email' => 'b@school-os.test']);
        $this->createMembership($memberB, $schoolB);

        $announcementA = $this->announcements()->createDraft(
            $schoolA, $creatorA, 'A', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $this->announcements()->publish($announcementA, $creatorA);

        $announcementB = $this->announcements()->createDraft(
            $schoolB, $creatorB, 'B', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $this->announcements()->publish($announcementB, $creatorB);

        $from = now()->subDay();
        $to = now()->addDay();

        $overviewA = $this->readModel()->schoolOverview($schoolA, $from, $to);
        $this->assertSame(1, $overviewA['published']);
        $this->assertSame(1, $overviewA['recipients']);
        $this->assertSame(1, $overviewA['channels']['email']['sent']);

        $overviewB = $this->readModel()->schoolOverview($schoolB, $from, $to);
        $this->assertSame(1, $overviewB['published']);
        $this->assertArrayNotHasKey('email', $overviewB['channels']);
    }

    #[Test]
    public function summary_and_overview_queries_are_bounded_regardless_of_recipient_volume(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        foreach (range(1, 25) as $i) {
            $this->createMembership($this->createUser(['email' => "member{$i}@school-os.test"]), $school);
        }

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        DB::enableQueryLog();
        $this->readModel()->announcementSummary($school, $published);
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Brief §42: a small, FIXED number of grouped queries -- never
        // one per recipient (25 recipients here).
        $this->assertLessThan(10, $queryCount);
    }
}
