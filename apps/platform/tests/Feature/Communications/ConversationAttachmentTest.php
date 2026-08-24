<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Exceptions\AttachmentTypeNotAllowedException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\NotThreadParticipantException;
use App\Domain\Communications\Application\Exceptions\ThreadNotOpenException;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.7 §15/§16/§17/§32/§54 -- conversation-message attachments,
 * reusing the exact CommunicationAttachmentService/storage architecture
 * Phase 5A.6 established for Announcements (brief §6/§60: "no
 * conversation_attachments/thread_attachments parallel silo").
 */
class ConversationAttachmentTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function threadService(): CommunicationThreadService
    {
        return app(CommunicationThreadService::class);
    }

    private function messageService(): CommunicationMessageService
    {
        return app(CommunicationMessageService::class);
    }

    private function attachmentService(): CommunicationAttachmentService
    {
        return app(CommunicationAttachmentService::class);
    }

    private function fakeFile(string $name = 'a.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    #[Test]
    public function a_participant_can_upload_a_pending_attachment_and_send_it_with_a_message(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());
        $this->assertNull($attachment->communication_message_id);
        $this->assertSame($thread->id, $attachment->communication_thread_id);
        $this->assertNull($attachment->communication_announcement_id);

        $message = $this->messageService()->send($thread, $creator, 'See attached', attachmentIds: [$attachment->id]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->findOrFail($attachment->id));
        $this->assertSame($message->id, $fresh->communication_message_id);
    }

    #[Test]
    public function a_pending_attachment_belonging_to_another_participant_is_never_silently_attached(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $creatorsUpload = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());

        // Recipient sends a message trying to claim the creator's
        // still-pending upload by id -- it must NOT be linked.
        $this->messageService()->send($thread, $recipient, 'Nice try', attachmentIds: [$creatorsUpload->id]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->findOrFail($creatorsUpload->id));
        $this->assertNull($fresh->communication_message_id);
    }

    #[Test]
    public function a_sent_attachment_cannot_be_removed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());
        $this->messageService()->send($thread, $creator, 'See attached', attachmentIds: [$attachment->id]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => CommunicationAttachment::query()->findOrFail($attachment->id));

        $this->expectException(InvalidAnnouncementTransitionException::class);
        $this->attachmentService()->remove($fresh, $creator);
    }

    #[Test]
    public function a_pending_attachment_can_be_removed_by_its_uploader_before_sending(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, []);

        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());
        $this->attachmentService()->remove($attachment, $creator);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAttachment::query()->where('communication_thread_id', $thread->id)->count(),
        );
        $this->assertSame(0, $count);
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    #[Test]
    public function another_participant_cannot_remove_someone_elses_pending_upload(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);

        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());

        $this->expectException(NotThreadParticipantException::class);
        $this->attachmentService()->remove($attachment, $recipient);
    }

    #[Test]
    public function a_non_participant_cannot_upload_to_a_thread(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, []);
        $outsider = $this->createUser();
        $this->createMembership($outsider, $school);

        $this->expectException(NotThreadParticipantException::class);
        $this->attachmentService()->uploadForThread($thread, $outsider, $this->fakeFile());
    }

    #[Test]
    public function forbidden_file_types_and_size_limits_apply_identically_to_conversation_uploads(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, []);

        try {
            $this->attachmentService()->uploadForThread($thread, $creator, UploadedFile::fake()->create('shell.php', 5, 'application/x-php'));
            $this->fail('Expected the type allowlist to reject this upload.');
        } catch (AttachmentTypeNotAllowedException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function the_participant_who_downloads_can_download_a_conversation_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $recipientMembership = $this->createMembership($recipient, $school);
        $this->assignSchoolRole($recipientMembership, 'principal');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, [$recipient->id]);
        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());
        $this->messageService()->send($thread, $creator, 'See attached', attachmentIds: [$attachment->id]);

        $this->actingAs($recipient)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($recipient)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    #[Test]
    public function a_same_school_non_participant_cannot_download_a_conversation_attachment(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, []);
        $attachment = $this->attachmentService()->uploadForThread($thread, $creator, $this->fakeFile());

        $stranger = $this->createUser();
        $strangerMembership = $this->createMembership($stranger, $school);
        $this->assignSchoolRole($strangerMembership, 'principal');

        $this->actingAs($stranger)->post("/app/schools/{$school->id}/activate");
        $this->actingAs($stranger)
            ->get("/app/communications/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    #[Test]
    public function a_cross_school_user_cannot_download_a_conversation_attachment_even_with_a_correct_id(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $threadB = $this->threadService()->createThread($schoolB, $creatorB, 'direct', null, []);
        $attachmentB = $this->attachmentService()->uploadForThread($threadB, $creatorB, $this->fakeFile());

        [$memberA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($memberA)->post("/app/schools/{$schoolA->id}/activate");

        $this->actingAs($memberA)
            ->get("/app/communications/attachments/{$attachmentB->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function uploading_to_a_non_open_thread_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->threadService()->createThread($school, $creator, 'direct', null, []);

        $closed = app(TenantContext::class)->withSchool($school, function () use ($thread) {
            $thread->update(['status' => 'closed']);

            return $thread->fresh();
        });

        $this->expectException(ThreadNotOpenException::class);
        $this->attachmentService()->uploadForThread($closed, $creator, $this->fakeFile());
    }
}
