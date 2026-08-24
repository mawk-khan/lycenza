<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Exceptions\AttachmentStorageException;
use App\Domain\Communications\Application\Exceptions\AttachmentTooLargeException;
use App\Domain\Communications\Application\Exceptions\AttachmentTypeNotAllowedException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\NotThreadParticipantException;
use App\Domain\Communications\Application\Exceptions\ThreadNotOpenException;
use App\Domain\Communications\Application\Exceptions\TooManyAttachmentsException;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * Phase 5A.6 §35 -- the ONE authoritative validation/association
 * boundary for Communication Hub attachments. Callers (controllers,
 * AnnouncementService) never validate file metadata or build a storage
 * path themselves.
 *
 * Upload ordering is deliberate (brief §21/§22 -- "storage and DB
 * writes are not perfectly atomic"):
 *
 *   1. Cheap, no-I/O checks first (lifecycle, count/size limits,
 *      declared-extension-vs-allowlist) -- reject before ever touching
 *      storage.
 *   2. Compute the SHA-256 checksum from the still-local temp upload.
 *   3. Write bytes to the configured disk under a server-generated key
 *      (TenantStoragePath::for(), never the raw filename -- brief §18).
 *   4. Create the `communication_attachments` row inside a DB
 *      transaction. If step 4 throws, the just-written file is deleted
 *      (compensating action) before the exception propagates -- a
 *      communication must never claim an attachment exists when its
 *      DB record failed to persist. If step 3 throws, no DB row is
 *      ever attempted -- a communication must never claim an
 *      attachment exists when storage failed (brief §22).
 *
 * There is no "pending"/"orphaned" status anywhere in this class
 * (brief §21's "prefer associate-during-transaction... do not build a
 * destructive cleanup scheduler"): a CommunicationAttachment row is
 * only ever created already associated with a real, existing
 * CommunicationAnnouncement the caller has already verified exists and
 * is editable -- there is no intermediate unassociated state to clean
 * up later.
 */
class CommunicationAttachmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationApprovalService $approvalService,
    ) {}

    public function upload(CommunicationAnnouncement $announcement, User $actor, UploadedFile $file): CommunicationAttachment
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $file) {
            if (! $announcement->isEditable()) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'attach');
            }

            $attachment = $this->store($announcement->school, $actor, $file, [
                'communication_announcement_id' => $announcement->id,
            ], "communications/announcements/{$announcement->id}", fn ($q) => $q->where('communication_announcement_id', $announcement->id), [
                'announcementId' => $announcement->id,
            ]);

            // Phase 5A.12 §25: an attachment is an approval-sensitive
            // field exactly like title/body/channels -- adding one to
            // an already-APPROVED announcement invalidates its
            // approval, the SAME fingerprint-recompute-and-compare hook
            // App\Domain\Communications\Application\AnnouncementService::updateDraft()
            // calls. A no-op for a Thread-owned upload (not reached
            // here) and for the overwhelming majority of announcements
            // that never used approval at all.
            $this->approvalService->invalidateIfFingerprintChanged($announcement, $actor);

            return $attachment;
        });
    }

    /**
     * Phase 5A.7 §6/§15 -- the conversation-message counterpart to
     * upload(): a pending attachment is uploaded against the owning
     * THREAD (the stable pre-message owner for a reply, exactly like
     * an Announcement is for that flow -- see the migration's docblock
     * for why a Thread, not a Message, is the right pre-send owner).
     * Every allowlist/size/count/checksum/storage rule from Phase 5A.6
     * applies unchanged -- no separate validation path.
     */
    public function uploadForThread(CommunicationThread $thread, User $actor, UploadedFile $file): CommunicationAttachment
    {
        return $this->context->withSchool($thread->school, function () use ($thread, $actor, $file) {
            if (! $thread->isOpen()) {
                throw new ThreadNotOpenException($thread->status);
            }

            if (! $this->isActiveParticipant($thread, $actor)) {
                throw new NotThreadParticipantException;
            }

            return $this->store($thread->school, $actor, $file, [
                'communication_thread_id' => $thread->id,
            ], "communications/threads/{$thread->id}", fn ($q) => $q->where('communication_thread_id', $thread->id), [
                'threadId' => $thread->id,
            ]);
        });
    }

    public function remove(CommunicationAttachment $attachment, User $actor): void
    {
        $this->context->withSchool($attachment->school, function () use ($attachment, $actor) {
            if ($attachment->communication_message_id !== null) {
                // Brief §23/§24 (Phase 5A.6), extended by Phase 5A.7
                // §16/§23 -- once a CommunicationMessage has been
                // linked (announcement published, or conversation
                // message sent), an attachment is historical and
                // immutable. Deliberately the SAME exception class for
                // both parent types (not a new Thread-specific one) --
                // AnnouncementAttachmentPublishTest::a_published_attachment_cannot_be_removed
                // already asserts on this class, and "already sent" is
                // genuinely the same invariant regardless of which
                // parent produced the message.
                throw new InvalidAnnouncementTransitionException('sent', 'remove attachment from');
            }

            $metadata = [];
            $announcement = null;

            if ($attachment->communication_announcement_id !== null) {
                $announcement = $attachment->announcement;

                if (! $announcement->isEditable()) {
                    throw new InvalidAnnouncementTransitionException($announcement->status, 'remove attachment from');
                }

                $metadata = ['announcementId' => $announcement->id];
            } else {
                $thread = $attachment->thread;

                if (! $thread->isOpen()) {
                    throw new ThreadNotOpenException($thread->status);
                }

                // Only the uploader may remove their own not-yet-sent
                // pending attachment -- another participant guessing/
                // enumerating an id they didn't upload must not be able
                // to delete someone else's pending file.
                if ($attachment->created_by_user_id !== $actor->id) {
                    throw new NotThreadParticipantException;
                }

                $metadata = ['threadId' => $thread->id];
            }

            DB::transaction(function () use ($attachment, $actor, $metadata) {
                $attachment->delete();

                $this->audit->school($attachment->school, 'communication_attachment.removed', actor: $actor, subject: $attachment, metadata: $metadata);
            });

            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);

            // Phase 5A.12 §25: removing an attachment is exactly as
            // approval-sensitive as adding one -- see upload()'s own
            // comment. Deliberately AFTER the attachment row is already
            // gone, so the recomputed fingerprint reflects the true
            // post-removal attachment set.
            if ($announcement !== null) {
                $this->approvalService->invalidateIfFingerprintChanged($announcement, $actor);
            }
        });
    }

    /**
     * Phase 5A.6 §11, generalized by Phase 5A.7 §32 -- the parent-
     * communication read-entitlement check. For an Announcement-owned
     * attachment: creator, resolved recipient, or communications.manage
     * (reused verbatim from AnnouncementController::show()'s own
     * authorization). For a Thread-owned attachment: an active
     * participant, or communications.manage. Never satisfied merely by
     * knowing the attachment's id (brief §5/§12).
     */
    public function authorizeRead(CommunicationAttachment $attachment, User $actor, School $school): bool
    {
        if ($attachment->school_id !== $school->id) {
            return false;
        }

        return $this->context->withSchool($school, function () use ($attachment, $actor) {
            if ($attachment->communication_announcement_id !== null) {
                $announcement = $attachment->announcement;

                if ($announcement === null) {
                    return false;
                }

                if ($announcement->created_by_user_id === $actor->id) {
                    return true;
                }

                if (app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $announcement->school)) {
                    return true;
                }

                return CommunicationAnnouncementRecipient::query()
                    ->where('announcement_id', $announcement->id)
                    ->where('user_id', $actor->id)
                    ->exists();
            }

            $thread = $attachment->thread;

            if ($thread === null) {
                return false;
            }

            if (app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $thread->school)) {
                return true;
            }

            return $this->isActiveParticipant($thread, $actor);
        });
    }

    private function isActiveParticipant(CommunicationThread $thread, User $user): bool
    {
        return $thread->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Phase 5A.7 §6 -- the shared validate/store/create core behind
     * both upload() and uploadForThread(): identical allowlist/size/
     * count/checksum/storage/compensating-action behavior regardless
     * of which pre-message owner the caller has already authorized
     * against. `$ownerColumns` supplies exactly the one owner FK
     * column to set (`communication_announcement_id` XOR
     * `communication_thread_id` -- the database CHECK constraint is
     * the final backstop, this is just which column a caller passes).
     *
     * @param  array<string, string>  $ownerColumns
     * @param  Closure(Builder<CommunicationAttachment>): Builder<CommunicationAttachment>  $scopeExisting
     * @param  array<string, mixed>  $auditMetadata
     */
    private function store(
        School $school,
        User $actor,
        UploadedFile $file,
        array $ownerColumns,
        string $pathPrefix,
        Closure $scopeExisting,
        array $auditMetadata,
    ): CommunicationAttachment {
        [$mimeType, $extension] = $this->assertAllowedType($file);
        $this->assertWithinSizeLimit($file);
        $this->assertWithinMessageLimits($scopeExisting, $file);

        $disk = (string) config('communications.attachments.disk');
        $checksum = hash_file('sha256', $file->getRealPath());
        $storageKey = (string) new UuidV7;
        $path = TenantStoragePath::for($school, "{$pathPrefix}/{$storageKey}.{$extension}");

        try {
            $stream = fopen($file->getRealPath(), 'r');
            $stored = Storage::disk($disk)->put($path, $stream);
        } catch (Throwable) {
            $stored = false;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            throw new AttachmentStorageException;
        }

        try {
            return DB::transaction(function () use ($school, $actor, $file, $mimeType, $checksum, $disk, $path, $ownerColumns, $auditMetadata) {
                $attachment = CommunicationAttachment::query()->create(array_merge([
                    'school_id' => $school->id,
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'original_filename' => $this->sanitizeDisplayName($file->getClientOriginalName()),
                    'safe_display_name' => $this->sanitizeDisplayName($file->getClientOriginalName()),
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                    'checksum_sha256' => $checksum,
                    'created_by_user_id' => $actor->id,
                ], $ownerColumns));

                $this->audit->school($school, 'communication_attachment.uploaded', actor: $actor, subject: $attachment, metadata: array_merge($auditMetadata, [
                    'sizeBytes' => $attachment->size_bytes,
                    'mimeType' => $attachment->mime_type,
                ]));

                return $attachment;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string} [sniffed mime type, matched extension]
     */
    private function assertAllowedType(UploadedFile $file): array
    {
        // Brief §15: the REAL, server-inspected content type -- Laravel's
        // UploadedFile::getMimeType() delegates to Symfony's mime-type
        // guesser, which uses the fileinfo extension against the
        // file's actual bytes, never the browser-supplied
        // Content-Type header (that value is available separately via
        // getClientMimeType(), never used here).
        $mimeType = $file->getMimeType();
        $declaredExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        $allowed = (array) config('communications.attachments.allowed_mime_types');

        if ($mimeType === null || ! array_key_exists($mimeType, $allowed)) {
            throw new AttachmentTypeNotAllowedException;
        }

        if (! in_array($declaredExtension, $allowed[$mimeType], true)) {
            throw new AttachmentTypeNotAllowedException;
        }

        return [$mimeType, $declaredExtension];
    }

    private function assertWithinSizeLimit(UploadedFile $file): void
    {
        $maxMb = (int) config('communications.attachments.max_file_size_mb');

        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new AttachmentTooLargeException($maxMb);
        }
    }

    /**
     * @param  Closure(Builder<CommunicationAttachment>): Builder<CommunicationAttachment>  $scopeExisting
     */
    private function assertWithinMessageLimits(Closure $scopeExisting, UploadedFile $file): void
    {
        $existing = $scopeExisting(CommunicationAttachment::query())->get(['size_bytes']);

        $maxCount = (int) config('communications.attachments.max_per_message');
        if ($existing->count() + 1 > $maxCount) {
            throw new TooManyAttachmentsException('attachment_count_exceeded');
        }

        $maxTotalBytes = (int) config('communications.attachments.max_total_size_mb') * 1024 * 1024;
        if ($existing->sum('size_bytes') + $file->getSize() > $maxTotalBytes) {
            throw new TooManyAttachmentsException('attachment_total_size_exceeded');
        }
    }

    /**
     * Brief §18: preserves the original filename only as normalized
     * DISPLAY metadata -- never used to build a storage path. Strips
     * directory components (basename only, defeating path traversal
     * via a crafted filename), control characters, and caps length
     * well under the database column limit.
     */
    private function sanitizeDisplayName(string $rawName): string
    {
        $name = basename($rawName);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'attachment';
        }

        return mb_substr($name, 0, 180);
    }
}
