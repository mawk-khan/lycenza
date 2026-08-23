<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\Exceptions\AttachmentStorageException;
use App\Domain\Communications\Application\Exceptions\AttachmentTooLargeException;
use App\Domain\Communications\Application\Exceptions\AttachmentTypeNotAllowedException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\TooManyAttachmentsException;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.6 §41/§43 -- file validation and historical-immutability
 * coverage for App\Domain\Communications\Application\CommunicationAttachmentService,
 * exercised directly (not via HTTP) so a validation failure's exact
 * exception/failure code is asserted precisely.
 */
class CommunicationAttachmentServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function service(): CommunicationAttachmentService
    {
        return app(CommunicationAttachmentService::class);
    }

    private function attachmentCount($school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->count());
    }

    #[Test]
    public function a_permitted_file_type_is_accepted_and_stored_privately(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $attachment = $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('report-card.pdf', 100, 'application/pdf'),
        );

        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('report-card.pdf', $attachment->safe_display_name);
        $this->assertSame($announcement->id, $attachment->communication_announcement_id);
        $this->assertNull($attachment->communication_message_id);

        // Brief §10: private disk, never the `public` disk, and never a
        // raw filename used as the storage key.
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertStringNotContainsString('report-card.pdf', $attachment->storage_path);
    }

    #[Test]
    public function a_disallowed_file_type_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $this->expectException(AttachmentTypeNotAllowedException::class);

        $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('shell.php', 5, 'application/x-php'),
        );
    }

    #[Test]
    public function a_sniffed_mime_type_that_does_not_match_the_declared_extension_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $this->expectException(AttachmentTypeNotAllowedException::class);

        // The sniffed type (application/pdf) IS on the allowlist, but
        // '.exe' is not one of its permitted extensions -- brief §15's
        // "server-side inspection, not extension alone" cuts both ways.
        $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('totally-a-report.exe', 5, 'application/pdf'),
        );
    }

    #[Test]
    public function svg_and_macro_enabled_office_formats_are_never_accepted(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        foreach ([
            ['icon.svg', 'image/svg+xml'],
            ['macro.docm', 'application/vnd.ms-word.document.macroEnabled.12'],
            ['macro.xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12'],
        ] as [$name, $mime]) {
            try {
                $this->service()->upload($announcement, $creator, UploadedFile::fake()->create($name, 5, $mime));
                $this->fail("Expected {$name} ({$mime}) to be rejected.");
            } catch (AttachmentTypeNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_file_exceeding_the_configured_size_limit_is_rejected(): void
    {
        Config::set('communications.attachments.max_file_size_mb', 1);

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $this->expectException(AttachmentTooLargeException::class);

        $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('big.pdf', 2000, 'application/pdf'),
        );
    }

    #[Test]
    public function exceeding_the_per_message_attachment_count_limit_is_rejected(): void
    {
        Config::set('communications.attachments.max_per_message', 2);

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $this->service()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $this->service()->upload($announcement, $creator, UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));

        $this->expectException(TooManyAttachmentsException::class);
        $this->service()->upload($announcement, $creator, UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'));
    }

    #[Test]
    public function exceeding_the_total_attachment_bytes_limit_is_rejected(): void
    {
        Config::set('communications.attachments.max_per_message', 10);
        Config::set('communications.attachments.max_total_size_mb', 1);

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $this->service()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 800, 'application/pdf'));

        try {
            $this->service()->upload($announcement, $creator, UploadedFile::fake()->create('b.pdf', 800, 'application/pdf'));
            $this->fail('Expected TooManyAttachmentsException for exceeding total message attachment bytes.');
        } catch (TooManyAttachmentsException $e) {
            $this->assertSame('attachment_total_size_exceeded', $e->failureCode);
        }
    }

    #[Test]
    public function a_dangerous_or_path_traversal_style_filename_is_sanitized_to_a_safe_display_name(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $attachment = $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create("../../etc/passwd\x01evil.pdf", 5, 'application/pdf'),
        );

        $this->assertStringNotContainsString('..', $attachment->safe_display_name);
        $this->assertStringNotContainsString('/', $attachment->safe_display_name);
        $this->assertStringNotContainsString("\x01", $attachment->safe_display_name);
    }

    #[Test]
    public function an_empty_upload_is_rejected_by_the_controller_validation_layer(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($creator)->post("/app/schools/{$school->id}/activate");
        $announcement = $this->createAnnouncement($school, $creator);

        $this->actingAs($creator)
            ->post("/app/communications/announcements/{$announcement->id}/attachments", [])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function a_storage_write_failure_never_creates_an_attachment_row(): void
    {
        // Brief §22: simulates a storage backend that cannot be
        // reached at all -- a disk name with no configured driver
        // makes Storage::disk() itself throw, exactly like a real
        // misconfigured/unreachable object-store endpoint would.
        Config::set('communications.attachments.disk', 'nonexistent_disk_for_test');

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        try {
            $this->service()->upload(
                $announcement,
                $creator,
                UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            );
            $this->fail('Expected AttachmentStorageException.');
        } catch (AttachmentStorageException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->attachmentCount($school));
    }

    #[Test]
    public function attachments_cannot_be_added_to_a_published_announcement(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator, ['status' => 'published']);

        $this->expectException(InvalidAnnouncementTransitionException::class);

        $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'),
        );
    }

    #[Test]
    public function attachments_can_be_removed_while_the_announcement_is_still_a_draft(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $attachment = $this->service()->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );

        $this->service()->remove($attachment, $creator);

        $this->assertSame(0, $this->attachmentCount($school));
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }
}
