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
 * Phase 5A.6 §25 (mandatory invariant) -- create draft with attachment
 * -> schedule -> due publish must deliver the exact same attachment
 * identity that existed at creation time. There is no versioned
 * document model in this checkpoint (brief §8), so the guarantee is
 * structural: NOTHING in this codebase can ever rewrite
 * `communication_attachments.storage_path`/`checksum_sha256` after
 * creation (CommunicationAttachmentService has no such method) -- these
 * tests prove that identity survives the full schedule -> due-publish
 * path unchanged, and that a cancelled schedule has no delivery side
 * effects.
 */
class ScheduledAnnouncementAttachmentTest extends TestCase
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

    #[Test]
    public function a_scheduled_announcements_attachment_survives_due_publication_unchanged(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('policy.pdf', 20, 'application/pdf'),
        );
        $originalPath = $attachment->storage_path;
        $originalChecksum = $attachment->checksum_sha256;
        $originalDisplayName = $attachment->safe_display_name;

        $scheduled = $this->announcements()->schedule($announcement, $creator, now()->addSeconds(1));

        $this->travel(2)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $scheduled->fresh());
        $this->assertSame('published', $fresh->status);

        $publishedAttachment = $this->findAttachment($school, $attachment->id);
        $this->assertSame($fresh->message_id, $publishedAttachment->communication_message_id);
        $this->assertSame($originalPath, $publishedAttachment->storage_path);
        $this->assertSame($originalChecksum, $publishedAttachment->checksum_sha256);
        $this->assertSame($originalDisplayName, $publishedAttachment->safe_display_name);
    }

    #[Test]
    public function rerunning_the_scheduler_does_not_duplicate_or_re_link_the_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $scheduled = $this->announcements()->schedule($announcement, $creator, now()->addSeconds(1));

        $this->travel(2)->seconds();
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);
        $this->artisan('communications:publish-scheduled')->assertExitCode(0);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAttachment::query()->where('communication_announcement_id', $scheduled->id)->count(),
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function cancelling_a_schedule_leaves_the_attachment_associated_and_still_editable(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $scheduled = $this->announcements()->schedule($announcement, $creator, now()->addMinutes(30));

        $cancelled = $this->announcements()->cancel($scheduled, $creator);

        $this->assertSame('cancelled', $cancelled->status);
        // No delivery side effects at all -- cancellation never touches
        // the scheduler/publish path.
        $fresh = $this->findAttachment($school, $attachment->id);
        $this->assertNull($fresh->communication_message_id);
        $this->assertSame($announcement->id, $fresh->communication_announcement_id);
    }

    #[Test]
    public function rescheduling_does_not_disturb_the_associated_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $attachment = $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $scheduled = $this->announcements()->schedule($announcement, $creator, now()->addMinutes(30));

        $this->announcements()->reschedule($scheduled, $creator, now()->addHours(2));

        $fresh = $this->findAttachment($school, $attachment->id);
        $this->assertSame($attachment->storage_path, $fresh->storage_path);
        $this->assertSame($attachment->checksum_sha256, $fresh->checksum_sha256);
    }
}
