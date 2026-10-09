<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationPriority;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationMessageFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $thread_id
 * @property string|null $announcement_id
 * @property string $sender_user_id
 * @property string $message_type
 * @property string $body
 * @property string $priority
 * @property string $status
 * @property string|null $reply_to_message_id
 * @property Carbon|null $edited_at
 */
class CommunicationMessage extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * Phase 5A.7 §21: microsecond precision (paired with this
     * checkpoint's migration widening the `created_at`/`updated_at`
     * columns to `timestamp(6)`) -- Laravel's default `'Y-m-d H:i:s'`
     * format truncates to whole seconds, which made two genuinely
     * distinct events (e.g. a read-cursor update and a reply) within
     * the same wall-clock second compare as simultaneous, breaking the
     * unread-derivation invariant (brief §21) under fast/automated
     * sends. See ConversationReadModel's docblock.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'school_id', 'thread_id', 'announcement_id', 'sender_user_id', 'message_type', 'body',
        'priority', 'status', 'reply_to_message_id', 'edited_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationMessageFactory
    {
        return CommunicationMessageFactory::new();
    }

    /** @return BelongsTo<CommunicationThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunicationThread::class, 'thread_id');
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function isAnnouncement(): bool
    {
        return $this->announcement_id !== null;
    }

    /** @return HasMany<CommunicationRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CommunicationRecipient::class, 'message_id');
    }

    /** @return HasMany<CommunicationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CommunicationAttachment::class, 'communication_message_id');
    }

    public function priorityEnum(): CommunicationPriority
    {
        return CommunicationPriority::from($this->priority);
    }
}
