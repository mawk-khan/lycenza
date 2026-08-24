<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property string $requirement
 * @property string $dispatch_mode
 * @property string|null $emergency_justification
 * @property string|null $emergency_declared_by_user_id
 * @property Carbon|null $emergency_declared_at
 * @property string $audience_type
 * @property Carbon|null $scheduled_at
 * @property string|null $scheduled_by_user_id
 * @property string|null $source_template_id
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
        'status', 'requirement', 'dispatch_mode', 'emergency_justification', 'emergency_declared_by_user_id',
        'emergency_declared_at', 'audience_type', 'scheduled_at', 'scheduled_by_user_id', 'source_template_id',
        'message_id', 'recipient_count', 'published_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'emergency_declared_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function emergencyDeclaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emergency_declared_by_user_id');
    }

    /** @return BelongsTo<CommunicationTemplate, $this> */
    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'source_template_id');
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

    /**
     * Phase 5B.1 -- the authored `student`/`guardian`/
     * `guardians_of_students` selection, parallel to audienceMembers()
     * above (which serves only `individual`).
     *
     * @return HasMany<CommunicationAnnouncementDomainAudienceMember, $this>
     */
    public function domainAudienceMembers(): HasMany
    {
        return $this->hasMany(CommunicationAnnouncementDomainAudienceMember::class, 'announcement_id');
    }

    /**
     * Phase 5B.3 -- the authored `grade`/`section` cohort definition,
     * parallel to domainAudienceMembers() above (which serves
     * `student`/`guardian`/`guardians_of_students`). At most one row
     * per announcement (database-enforced unique `announcement_id`).
     *
     * @return HasOne<CommunicationAnnouncementAcademicCohort, $this>
     */
    public function academicCohort(): HasOne
    {
        return $this->hasOne(CommunicationAnnouncementAcademicCohort::class, 'announcement_id');
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

    /** @return HasMany<CommunicationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CommunicationAttachment::class, 'communication_announcement_id');
    }

    /** @return HasMany<CommunicationApprovalRequest, $this> */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(CommunicationApprovalRequest::class, 'announcement_id');
    }

    public function priorityEnum(): CommunicationPriority
    {
        return CommunicationPriority::from($this->priority);
    }

    /**
     * Phase 5A.5 §9: deliberately a SEPARATE enum from priority --
     * CRITICAL priority does not imply Required, NORMAL does not imply
     * Optional. See App\Domain\Communications\Domain\CommunicationRequirement.
     */
    public function requirementEnum(): CommunicationRequirement
    {
        return CommunicationRequirement::from($this->requirement);
    }

    public function audienceTypeEnum(): CommunicationAudienceType
    {
        return CommunicationAudienceType::from($this->audience_type);
    }

    /**
     * Phase 5A.10 -- deliberately a THIRD, separate enum from both
     * priority and requirement. CRITICAL priority does not imply
     * Emergency; REQUIRED requirement does not imply Emergency (though
     * Emergency itself must be Required -- enforced in
     * App\Domain\Communications\Application\AnnouncementService).
     */
    public function dispatchModeEnum(): CommunicationDispatchMode
    {
        return CommunicationDispatchMode::from($this->dispatch_mode);
    }

    public function isEmergency(): bool
    {
        return $this->dispatch_mode === CommunicationDispatchMode::Emergency->value;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Phase 5A.12 §16: distinct from `isEditable()` -- while pending,
     * content is deliberately frozen (brief §29) until the requester
     * withdraws or the approver decides.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Phase 5A.4 §31, widened by Phase 5A.12 §29/§35/§36: still-
     * canonical-content-editable in DRAFT/SCHEDULED (unchanged from
     * 5A.4), and now also APPROVED/REJECTED -- editing either of those
     * is exactly what re-enters the approval cycle
     * (App\Domain\Communications\Application\Approval\CommunicationApprovalService::invalidateIfFingerprintChanged())
     * rather than being blocked outright. PENDING_APPROVAL is
     * deliberately NOT editable (brief §29: no silent editing behind
     * the approver's back -- withdraw first).
     */
    public function isEditable(): bool
    {
        return $this->isDraft() || $this->isScheduled() || $this->isApproved() || $this->isRejected();
    }
}
