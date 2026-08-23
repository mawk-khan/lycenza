<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Exceptions\AttachmentStorageException;
use App\Domain\Communications\Application\Exceptions\AttachmentTooLargeException;
use App\Domain\Communications\Application\Exceptions\AttachmentTypeNotAllowedException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\TooManyAttachmentsException;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
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
    ) {}

    public function upload(CommunicationAnnouncement $announcement, User $actor, UploadedFile $file): CommunicationAttachment
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $file) {
            if (! $announcement->isEditable()) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'attach');
            }

            [$mimeType, $extension] = $this->assertAllowedType($file);
            $this->assertWithinSizeLimit($file);
            $this->assertWithinMessageLimits($announcement, $file);

            $disk = (string) config('communications.attachments.disk');
            $checksum = hash_file('sha256', $file->getRealPath());
            $storageKey = (string) new UuidV7;
            $path = TenantStoragePath::for(
                $announcement->school,
                "communications/announcements/{$announcement->id}/{$storageKey}.{$extension}",
            );

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
                $attachment = DB::transaction(function () use ($announcement, $actor, $file, $mimeType, $checksum, $disk, $path) {
                    $attachment = CommunicationAttachment::query()->create([
                        'school_id' => $announcement->school_id,
                        'communication_announcement_id' => $announcement->id,
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'original_filename' => $this->sanitizeDisplayName($file->getClientOriginalName()),
                        'safe_display_name' => $this->sanitizeDisplayName($file->getClientOriginalName()),
                        'mime_type' => $mimeType,
                        'size_bytes' => $file->getSize(),
                        'checksum_sha256' => $checksum,
                        'created_by_user_id' => $actor->id,
                    ]);

                    $this->audit->school($announcement->school, 'communication_attachment.uploaded', actor: $actor, subject: $attachment, metadata: [
                        'announcementId' => $announcement->id,
                        'sizeBytes' => $attachment->size_bytes,
                        'mimeType' => $attachment->mime_type,
                    ]);

                    return $attachment;
                });
            } catch (Throwable $e) {
                Storage::disk($disk)->delete($path);

                throw $e;
            }

            return $attachment;
        });
    }

    public function remove(CommunicationAttachment $attachment, User $actor): void
    {
        $this->context->withSchool($attachment->school, function () use ($attachment, $actor) {
            $announcement = $attachment->announcement;

            // Brief §23/§24: once a CommunicationMessage has been
            // linked (the announcement has been published), an
            // attachment is historical and immutable -- there is no
            // removal path here at all, matching "no attachment
            // mutation after publish."
            if (! $announcement->isEditable() || $attachment->communication_message_id !== null) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'remove attachment from');
            }

            DB::transaction(function () use ($attachment, $announcement, $actor) {
                $attachment->delete();

                $this->audit->school($announcement->school, 'communication_attachment.removed', actor: $actor, subject: $attachment, metadata: [
                    'announcementId' => $announcement->id,
                ]);
            });

            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
        });
    }

    /**
     * Phase 5A.6 §11 -- the parent-communication read-entitlement check,
     * reused verbatim from AnnouncementController::show()'s own
     * authorization (creator, resolved recipient, or communications.manage)
     * rather than re-deriving a second definition of "may read this
     * announcement." Never satisfied merely by knowing the attachment's
     * id (brief §5/§12).
     */
    public function authorizeRead(CommunicationAttachment $attachment, User $actor, School $school): bool
    {
        if ($attachment->school_id !== $school->id) {
            return false;
        }

        return $this->context->withSchool($school, function () use ($attachment, $actor) {
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
        });
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

    private function assertWithinMessageLimits(CommunicationAnnouncement $announcement, UploadedFile $file): void
    {
        $existing = CommunicationAttachment::query()
            ->where('communication_announcement_id', $announcement->id)
            ->get(['size_bytes']);

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
