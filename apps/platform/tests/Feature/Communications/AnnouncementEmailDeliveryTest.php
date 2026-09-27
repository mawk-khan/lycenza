<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
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
 * Phase 5A.3 §37: proves the full publish -> delivery-creation path
 * for the EMAIL channel, reusing exactly the same
 * App\Domain\Communications\Application\CommunicationDeliveryFactory
 * idempotent-creation logic as IN_APP (brief §6/§22). Mail::fake()
 * throughout -- QUEUE_CONNECTION=sync in tests means
 * App\Jobs\ProcessCommunicationDeliveryJob (and therefore
 * EmailChannelDriver) runs synchronously inside publish(), so these
 * assertions can inspect final delivery state immediately.
 */
class AnnouncementEmailDeliveryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    #[Test]
    public function an_in_app_only_announcement_creates_no_email_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $channels = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->pluck('channel')->all());

        $this->assertSame(['in_app'], $channels);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function requesting_email_creates_exactly_one_email_delivery_per_recipient_alongside_in_app(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $memberA = $this->createUser(['email' => 'a@school-os.test']);
        $memberB = $this->createUser(['email' => 'b@school-os.test']);
        $this->createMembership($memberA, $school);
        $this->createMembership($memberB, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $deliveries = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get(['channel', 'status', 'destination_snapshot']));

        $this->assertCount(4, $deliveries); // 2 recipients x 2 channels
        $this->assertSame(2, $deliveries->where('channel', 'in_app')->count());
        $emailDeliveries = $deliveries->where('channel', 'email');
        $this->assertSame(2, $emailDeliveries->count());
        $this->assertTrue($emailDeliveries->every(fn (CommunicationDelivery $d) => $d->status === 'sent'));
        $this->assertEmailAcceptedCount(2);
    }

    #[Test]
    public function republishing_does_not_duplicate_the_email_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['email' => 'member@school-os.test']);
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $first = $this->service()->publish($announcement, $creator);
        $this->service()->publish($first, $creator);

        $context = app(TenantContext::class);
        $emailCount = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $first->message_id)->pluck('id'))
            ->where('channel', 'email')->count());

        $this->assertSame(1, $emailCount);
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function one_recipients_email_failure_does_not_affect_another_recipients_successful_in_app_delivery(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        // No email override -- User factory always produces a
        // syntactically valid faker email, so to exercise a genuine
        // email failure we directly corrupt one recipient's stored
        // address (a state a Student/Guardian import or manual data
        // fix could plausibly produce).
        $memberWithBadEmail = $this->createUser(['email' => 'not-a-valid-email']);
        $memberWithGoodEmail = $this->createUser();
        $this->createMembership($memberWithBadEmail, $school);
        $this->createMembership($memberWithGoodEmail, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $deliveries = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get(['recipient_id', 'channel', 'status', 'failure_code']));

        // Both in_app deliveries succeeded regardless of the email outcome.
        $this->assertTrue($deliveries->where('channel', 'in_app')->every(fn (CommunicationDelivery $d) => $d->status === 'delivered'));

        $emailStatuses = $deliveries->where('channel', 'email')->pluck('status')->sort()->values()->all();
        $this->assertSame(['failed', 'sent'], $emailStatuses);

        // AnnouncementService already resolved/validated the address
        // via EmailAddressResolver at publish time (brief §7), so an
        // unresolvable address never even reaches destination_snapshot
        // -- the driver sees no snapshot at all, hence
        // recipient_email_missing (not recipient_email_invalid, which
        // is the driver's OWN defensive re-check, exercised directly
        // in EmailChannelDriverTest).
        $failedEmail = $deliveries->where('channel', 'email')->firstWhere('status', 'failed');
        $this->assertSame('recipient_email_missing', $failedEmail->failure_code);

        // The Announcement itself is still fully published, not FAILED
        // (brief §29: partial channel failure never fails the whole
        // Announcement).
        $this->assertSame('published', $published->status);
    }

    #[Test]
    public function an_email_delivery_is_still_created_and_marked_failed_when_the_recipient_has_no_usable_email(): void
    {
        // brief §28: "prefer auditable behavior rather than silently
        // pretending every member was email-deliverable" -- a missing/
        // invalid address still produces a durable, visible delivery
        // record, not a silent skip.
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['email' => 'still-not-valid']);
        $this->createMembership($member, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $context = app(TenantContext::class);
        $emailDelivery = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->first());

        $this->assertNotNull($emailDelivery);
        $this->assertSame('failed', $emailDelivery->status);
        $this->assertSame('recipient_email_missing', $emailDelivery->failure_code);
        $this->assertNotNull($emailDelivery->failed_at);
    }
}
