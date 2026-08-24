<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
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
 * Phase 5A.6 §23/§25/§42 (mandatory) -- proves the message-id backfill
 * at publish time and the resulting historical immutability: no
 * attachment mutation is possible once a CommunicationMessage exists
 * for the Announcement.
 */
class AnnouncementAttachmentPublishTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function attachments(): CommunicationAttachmentService
    {
        return app(CommunicationAttachmentService::class);
    }

    private function findAttachment($school, string $id): CommunicationAttachment
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->findOrFail($id));
    }

    private function countAttachments($school, string $announcementId): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAttachment::query()->where('communication_announcement_id', $announcementId)->count(),
        );
    }

    #[Test]
    public function publishing_backfills_the_message_id_on_every_attachment_without_re_creating_the_row(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );

        $this->assertNull($attachment->communication_message_id);
        $originalStoragePath = $attachment->storage_path;
        $originalChecksum = $attachment->checksum_sha256;

        $published = $this->announcements()->publish($announcement, $creator);

        $fresh = $this->findAttachment($school, $attachment->id);
        $this->assertSame($published->message_id, $fresh->communication_message_id);
        $this->assertSame($originalStoragePath, $fresh->storage_path);
        $this->assertSame($originalChecksum, $fresh->checksum_sha256);
        $this->assertSame(1, $this->countAttachments($school, $announcement->id));
    }

    #[Test]
    public function no_attachment_can_be_added_once_the_announcement_is_published(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->attachments()->upload(
            $published,
            $creator,
            UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'),
        );
    }

    #[Test]
    public function a_published_attachment_cannot_be_removed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );
        $this->announcements()->publish($announcement, $creator);

        $fresh = $this->findAttachment($school, $attachment->id);

        $this->expectException(InvalidAnnouncementTransitionException::class);
        $this->attachments()->remove($fresh, $creator);
    }

    #[Test]
    public function republishing_does_not_duplicate_or_re_link_attachments(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $first = $this->announcements()->publish($announcement, $creator);
        $second = $this->announcements()->publish($first, $creator);

        $this->assertSame($first->message_id, $second->message_id);
        $this->assertSame(1, $this->countAttachments($school, $announcement->id));
    }
}
