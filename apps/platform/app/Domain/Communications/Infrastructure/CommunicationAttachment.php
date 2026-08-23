<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5A.6 §5/§7/§23, normalized by Phase 5A.7 §6 -- one uploaded
 * file, owned by exactly one School, attached to exactly one PRE-
 * MESSAGE owner (a CommunicationAnnouncement OR a CommunicationThread
 * -- `communication_attachments_one_parent_check` enforces exactly
 * one of the two is ever set) and, once the owning message exists, the
 * CommunicationMessage it produced. Immutable once created: this class
 * deliberately has no update-the-bytes path -- `storage_path`/
 * `checksum_sha256` never change after creation, which is the entire
 * mechanism behind the "scheduled attachment snapshot" invariant
 * (Phase 5A.6 brief §25) -- there is no versioned document model to
 * pin a version number against, so immutability of THIS row is what
 * makes the guarantee hold for both announcements and conversations.
 *
 * @use HasFactory<CommunicationAttachmentFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $communication_announcement_id
 * @property string|null $communication_thread_id
 * @property string|null $communication_message_id
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string $safe_display_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $created_by_user_id
 */
class CommunicationAttachment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'communication_announcement_id', 'communication_thread_id', 'communication_message_id',
        'storage_disk', 'storage_path', 'original_filename', 'safe_display_name',
        'mime_type', 'size_bytes', 'checksum_sha256', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    protected static function newFactory(): CommunicationAttachmentFactory
    {
        return CommunicationAttachmentFactory::new();
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'communication_announcement_id');
    }

    /** @return BelongsTo<CommunicationThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunicationThread::class, 'communication_thread_id');
    }

    /** @return BelongsTo<CommunicationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
