<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationApprovalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 5A.12 §14/§15 -- one submission cycle's approval request/
 * decision. `status` transitions in place (pending -> approved|
 * rejected|cancelled|invalidated); a NEW row is created for every
 * resubmission, so historical decisions are never overwritten (brief
 * §64). See the creating migration's docblock for the full rationale,
 * including why `snapshot` exists alongside `fingerprint`.
 *
 * @use HasFactory<CommunicationApprovalRequestFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $requested_by_user_id
 * @property Carbon $requested_at
 * @property string $fingerprint
 * @property array<string, mixed> $snapshot
 * @property string $status
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
class CommunicationApprovalRequest extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'announcement_id', 'requested_by_user_id', 'requested_at',
        'fingerprint', 'snapshot', 'status', 'decided_by_user_id', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationApprovalRequestFactory
    {
        return CommunicationApprovalRequestFactory::new();
    }

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
