<?php

namespace App\Domain\Identity\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\GuardianAccountInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 5D.3 -- Identity-domain. See the migration's docblock for the
 * full schema rationale (token hashing, destination-email-hash
 * contact-drift detection, derived "expired" state).
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $guardian_id
 * @property string|null $student_id
 * @property string $token_hash
 * @property string $destination_email_hash
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property string $invited_by_user_id
 * @property string|null $revoked_by_user_id
 */
class GuardianAccountInvitation extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'identity_account_invitations';

    protected $fillable = [
        'school_id', 'guardian_id', 'student_id', 'token_hash', 'destination_email_hash',
        'status', 'expires_at', 'invited_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GuardianAccountInvitationFactory
    {
        return GuardianAccountInvitationFactory::new();
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * "Expired" is deliberately derived, never a physical 4th status
     * value -- see the migration's docblock.
     */
    public function isExpired(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }

    /**
     * A pending invitation is only actually usable if it is neither
     * expired nor already revoked/accepted -- the single predicate the
     * acceptance controller and the "one active pending invite"
     * read-model both consult, so the definition of "usable" cannot
     * drift between them.
     */
    public function isUsable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Read-model convenience for the admin UI (brief §31): the real
     * three-value column, with the derived "expired" case folded in --
     * never persisted, recomputed on every read.
     */
    public function effectiveStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    /**
     * @param  Builder<GuardianAccountInvitation>  $query
     * @return Builder<GuardianAccountInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
