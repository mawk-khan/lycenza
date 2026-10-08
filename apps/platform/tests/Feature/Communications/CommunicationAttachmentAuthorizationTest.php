<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.6 §41 -- HTTP-level authorization coverage: upload/remove/
 * download all re-verify real School membership and parent-communication
 * read-entitlement (brief §11), never merely "an id was supplied."
 */
class CommunicationAttachmentAuthorizationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function attach($announcement, $actor): CommunicationAttachment
    {
        return app(CommunicationAttachmentService::class)->upload(
            $announcement,
            $actor,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );
    }

    private function findAttachmentByAnnouncement($school, string $announcementId): CommunicationAttachment
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAttachment::query()->where('communication_announcement_id', $announcementId)->firstOrFail(),
        );
    }

    #[Test]
    public function a_guest_cannot_upload_or_download(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $attachment = $this->attach($announcement, $creator);

        $this->post("/app/communications/announcements/{$announcement->id}/attachments", [])
            ->assertRedirect('/login');
        $this->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertRedirect('/login');
    }

    #[Test]
    public function the_announcement_creator_can_upload_and_download_their_own_draft_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);
        $announcement = $this->createAnnouncement($school, $creator);

        $this->actingAs($creator)
            ->post("/app/communications/announcements/{$announcement->id}/attachments", [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect("/app/communications/announcements/{$announcement->id}");

        $attachment = $this->findAttachmentByAnnouncement($school, $announcement->id);

        $this->actingAs($creator)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    #[Test]
    public function an_ordinary_member_with_no_relationship_to_the_draft_cannot_download_it(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $attachment = $this->attach($announcement, $creator);

        $stranger = $this->createUser();
        $this->createMembership($stranger, $school);
        $this->activate($stranger, $school);

        $this->actingAs($stranger)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    #[Test]
    public function a_resolved_recipient_of_a_published_announcement_can_download_its_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);

        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $bare = $this->createUser();
        $this->createMembership($bare, $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->attach($announcement, $creator);
        $published = app(AnnouncementService::class)->publish($announcement, $creator);
        $attachment = $this->findAttachmentByAnnouncement($school, $published->id);

        // POR.1 (ADR 0070 §24.8): the staff Hub download needs `communications.view`, like the
        // announcement page itself -- a resolved recipient with only a membership is refused.
        $this->activate($bare, $school);
        $this->actingAs($bare)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertForbidden();

        $this->assignSchoolRole($recipientMembership, 'principal');
        $this->activate($recipient, $school);
        $this->actingAs($recipient)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    #[Test]
    public function communications_manage_can_download_any_announcements_attachment_even_without_being_a_recipient(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $attachment = $this->attach($announcement, $creator);

        $manager = $this->createUser();
        $membership = $this->createMembership($manager, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($manager, $school);

        $this->actingAs($manager)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    #[Test]
    public function school_a_cannot_download_school_bs_attachment_even_with_a_correct_id(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $attachmentB = $this->attach($announcementB, $creatorB);

        [$memberA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($memberA, $schoolA);

        // schoolA is the ACTIVE context, but the attachment id genuinely
        // belongs to schoolB -- RLS makes the row invisible under
        // schoolA's context, so this must 404, not merely 403 (brief
        // §12: "forged message_id/file_id rejected").
        $this->actingAs($memberA)
            ->get("/app/communications/attachments/{$attachmentB->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function a_forged_announcement_id_on_upload_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);

        $this->actingAs($creator)
            ->post('/app/communications/announcements/00000000-0000-7000-8000-000000000000/attachments', [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_non_creator_non_manager_cannot_upload_to_someone_elses_draft(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $stranger = $this->createUser();
        $this->createMembership($stranger, $school);
        $this->activate($stranger, $school);

        $this->actingAs($stranger)
            ->post("/app/communications/announcements/{$announcement->id}/attachments", [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function removing_an_attachment_that_does_not_belong_to_the_given_announcement_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($creator, $school);
        $announcementA = $this->createAnnouncement($school, $creator);
        $announcementB = $this->createAnnouncement($school, $creator);
        $attachmentOfB = $this->attach($announcementB, $creator);

        $this->actingAs($creator)
            ->delete("/app/communications/announcements/{$announcementA->id}/attachments/{$attachmentOfB->id}")
            ->assertNotFound();
    }
}
