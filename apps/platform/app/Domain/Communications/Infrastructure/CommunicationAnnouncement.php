<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationAnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationAnnouncementFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $created_by_user_id
 * @property string $title
 * @property string $body
 * @property string $priority
 * @property string $status
 * @property string $audience_type
 * @property string|null $message_id
 * @property int|null $recipient_count
 * @property Carbon|null $published_at
 * @property Carbon|null $cancelled_at
 */
class CommunicationAnnouncement extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'campus_id', 'created_by_user_id', 'title', 'body', 'priority',
        'status', 'audience_type', 'message_id', 'recipient_count', 'published_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationAnnouncementFactory
    {
        return CommunicationAnnouncementFactory::new();
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<CommunicationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    /** @return HasMany<CommunicationAnnouncementAudienceMember, $this> */
    public function audienceMembers(): HasMany
    {
        return $this->hasMany(CommunicationAnnouncementAudienceMember::class, 'announcement_id');
    }

    /** @return HasMany<CommunicationAnnouncementRecipient, $this> */
    public function resolvedRecipients(): HasMany
    {
        return $this->hasMany(CommunicationAnnouncementRecipient::class, 'announcement_id');
    }

    /** @return HasMany<CommunicationAnnouncementChannel, $this> */
    public function requestedChannels(): HasMany
    {
        return $this->hasMany(CommunicationAnnouncementChannel::class, 'announcement_id');
    }

    public function priorityEnum(): CommunicationPriority
    {
        return CommunicationPriority::from($this->priority);
    }

    public function audienceTypeEnum(): CommunicationAudienceType
    {
        return CommunicationAudienceType::from($this->audience_type);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
