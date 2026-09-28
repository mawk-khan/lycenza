<?php

namespace App\Domain\Identity\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.12B (ADR 0059 sections 6, 18): one School's staff account
 * invitation -- School-owned (forced RLS), bound to ONE canonical
 * destination email. `pending -> accepted | revoked`; "expired" is derived.
 * Only SHA-256(secret) is stored. The destination email is Sensitive
 * contact data: never logged, never in audit metadata.
 *
 * @property string $id
 * @property string $school_id
 * @property string $destination_email
 * @property string $selector
 * @property string $secret_hash
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property string|null $invited_by_user_id
 * @property string|null $revoked_by_user_id
 * @property string|null $email_message_id
 * @property Carbon $created_at
 */
class StaffAccountInvitation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return HasMany<StaffAccountInvitationRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(StaffAccountInvitationRole::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    /** pending | expired | accepted | revoked -- "expired" is derived. */
    public function effectiveStatus(): string
    {
        return $this->status === self::STATUS_PENDING && $this->isExpired() ? 'expired' : $this->status;
    }
}
