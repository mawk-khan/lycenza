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
 * Phase 5A.8 §14/§45 -- announcement in-app read state, reusing the
 * `communication_deliveries.read_at`/`status='read'` column Phase
 * 5A.1 already reserved (see AnnouncementService::markRead()'s
 * docblock). In-app read state is distinct from email delivery/open
 * status -- these tests prove the EMAIL delivery row is never touched.
 */
class AnnouncementReadStateTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function inAppDeliveryFor($school, string $messageId, string $userId): CommunicationDelivery
    {
        return app(TenantContext::class)->withSchool($school, function () use ($messageId, $userId) {
            $recipient = CommunicationRecipient::query()
                ->where('message_id', $messageId)
                ->where('recipient_user_id', $userId)
                ->firstOrFail();

            return $recipient->deliveries()->where('channel', 'in_app')->firstOrFail();
        });
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_recipient_starts_unread(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $delivery = $this->inAppDeliveryFor($school, $published->message_id, $recipient->id);
        $this->assertNull($delivery->read_at);
        $this->assertSame('delivered', $delivery->status);
    }

    #[Test]
    public function opening_the_detail_page_marks_only_that_recipients_delivery_read(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipientA = $this->createUser();
        $recipientB = $this->createUser();
        $recipientAMembership = $this->createMembership($recipientA, $school);
        $this->assignSchoolRole($recipientAMembership, 'principal');
        $this->createMembership($recipientB, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->activate($recipientA, $school);
        $this->actingAs($recipientA)->get("/app/communications/announcements/{$published->id}")->assertOk();

        $deliveryA = $this->inAppDeliveryFor($school, $published->message_id, $recipientA->id);
        $deliveryB = $this->inAppDeliveryFor($school, $published->message_id, $recipientB->id);

        $this->assertNotNull($deliveryA->read_at);
        $this->assertSame('read', $deliveryA->status);
        // Sender/other recipient unaffected.
        $this->assertNull($deliveryB->read_at);
        $this->assertSame('delivered', $deliveryB->status);
    }

    #[Test]
    public function read_state_persists_across_requests(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->activate($recipient, $school);
        $this->actingAs($recipient)->get("/app/communications/announcements/{$published->id}")->assertOk();
        $firstReadAt = $this->inAppDeliveryFor($school, $published->message_id, $recipient->id)->read_at;

        $this->actingAs($recipient)->get("/app/communications/announcements/{$published->id}")->assertOk();
        $secondReadAt = $this->inAppDeliveryFor($school, $published->message_id, $recipient->id)->read_at;

        $this->assertNotNull($firstReadAt);
        $this->assertTrue($firstReadAt->equalTo($secondReadAt));
    }

    #[Test]
    public function the_creator_viewing_their_own_announcement_does_not_mark_a_recipients_delivery_read(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->activate($creator, $school);
        $this->actingAs($creator)->get("/app/communications/announcements/{$published->id}")->assertOk();

        $deliveryB = $this->inAppDeliveryFor($school, $published->message_id, $recipient->id);
        $this->assertNull($deliveryB->read_at);
    }

    #[Test]
    public function a_non_recipient_cannot_mark_the_announcement_read(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $manager = $this->createUser();
        $managerMembership = $this->createMembership($manager, $school);
        $this->assignSchoolRole($managerMembership, 'school_admin');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->service()->publish($announcement, $creator);

        // The manager can view (communications.manage) without being a
        // resolved recipient -- viewing must never mark a delivery
        // read for someone who was never a recipient in the first
        // place.
        $this->activate($manager, $school);
        $this->actingAs($manager)->get("/app/communications/announcements/{$published->id}")->assertOk();

        $recipientDelivery = $this->inAppDeliveryFor($school, $published->message_id, $recipient->id);
        $this->assertNull($recipientDelivery->read_at);
    }

    #[Test]
    public function school_a_cannot_alter_school_bs_read_state(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $recipientB = $this->createUser();
        $this->createMembership($recipientB, $schoolB);

        $announcementB = $this->service()->createDraft(
            $schoolB, $creatorB, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $publishedB = $this->service()->publish($announcementB, $creatorB);

        [$memberA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($memberA, $schoolA);

        // schoolA is the active context; announcementB genuinely
        // belongs to schoolB -- RLS makes it invisible, so this 404s
        // rather than mutating anything.
        $this->actingAs($memberA)->get("/app/communications/announcements/{$publishedB->id}")->assertNotFound();

        $recipientDelivery = $this->inAppDeliveryFor($schoolB, $publishedB->message_id, $recipientB->id);
        $this->assertNull($recipientDelivery->read_at);
    }

    #[Test]
    public function email_delivery_status_is_never_touched_by_in_app_read_state(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser(['email' => 'recipient@school-os.test']);
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');

        $announcement = $this->service()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->service()->publish($announcement, $creator);

        $this->activate($recipient, $school);
        $this->actingAs($recipient)->get("/app/communications/announcements/{$published->id}")->assertOk();

        $context = app(TenantContext::class);
        $emailDelivery = $context->withSchool($school, function () use ($published, $recipient) {
            $r = CommunicationRecipient::query()
                ->where('message_id', $published->message_id)
                ->where('recipient_user_id', $recipient->id)
                ->firstOrFail();

            return $r->deliveries()->where('channel', 'email')->firstOrFail();
        });

        $this->assertNull($emailDelivery->read_at);
        $this->assertSame('sent', $emailDelivery->status);
    }
}
